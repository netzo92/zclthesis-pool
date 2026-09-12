<?php
require __DIR__ . '/fixture.php';
use app\services\ZclAmount;
use app\services\ZclPayoutLedger;
use app\services\ZclPayoutCoordinator;

class FakeWallet {
    public array $calls = [];
    public int $mutations = 0;
    public string $shieldBalance = '0';
    public array $utxos = [];
    public int $confirmations = 6;
    public string $opStatus = 'success';
    public bool $loseOperation = false;
    public bool $failMutation = false;
    public bool $failRead = false;
    public $duringMutation = null;
    public function __construct() {
        $this->utxos = [['address'=>config()['pool_taddress'],'generated'=>true,'spendable'=>true,'confirmations'=>101,'amount'=>'1.0']];
    }
    public function __invoke(string $method,array $params) {
        $this->calls[] = [$method,$params];
        switch ($method) {
            case 'getblockchaininfo': return ['chain'=>'main','blocks'=>1000,'headers'=>1000,'verificationprogress'=>1];
            case 'validateaddress': return ['isvalid'=>true,'ismine'=>$params[0]===config()['pool_taddress']];
            case 'z_validateaddress': return ['isvalid'=>true,'ismine'=>true,'type'=>'sapling'];
            case 'z_getbalance': return $this->shieldBalance;
            case 'listunspent': return $this->utxos;
            case 'z_shieldcoinbase':
            case 'z_sendmany':
                $this->mutations++;
                if ($this->duringMutation) ($this->duringMutation)();
                if ($this->failMutation) throw new RuntimeException('Transport timed out after possible broadcast');
                return $method==='z_shieldcoinbase' ? ['opid'=>'opid-shield-11111111'] : 'opid-send-22222222';
            case 'z_getoperationstatus':
                if ($this->failRead) throw new RuntimeException('Temporary status-read failure');
                if ($this->loseOperation) return [];
                return [['id'=>$params[0][0],'status'=>$this->opStatus,'result'=>['txid'=>str_repeat(str_contains($params[0][0],'shield')?'a':'b',64)]]];
            case 'gettransaction': return ['txid'=>$params[0],'confirmations'=>$this->confirmations,'walletconflicts'=>[]];
            default: throw new RuntimeException('Unexpected RPC '.$method);
        }
    }
}
function coordinator(PDO $db, FakeWallet $wallet, $enabled=true): ZclPayoutCoordinator {
    return new ZclPayoutCoordinator(new ZclPayoutLedger($db,1),$wallet,config(),$enabled);
}

check(ZclAmount::parse('20999999.99999999')===2099999999999999,'Large amount lost a zatoshi');
check(ZclAmount::decimal(1)==='0.00000001','One-zatoshi decimal');
foreach (['-1','0.000000001','1e-8','21000000.00000001'] as $bad) {
    try { ZclAmount::parse($bad); throw new RuntimeException('Accepted bad amount'); }
    catch (InvalidArgumentException $expected) {}
}
echo "PASS exact fixed-point amounts\n";

$db=setup(); $wallet=new FakeWallet();
foreach ([false,'true',1] as $disabled) check(coordinator($db,$wallet,$disabled)->tick()==='disabled','Payout enabled without explicit boolean true');
check($wallet->calls===[] && scalar($db,'SELECT COUNT(*) FROM payouts')===0,'Disabled worker had side effects');
echo "PASS disabled worker has no DB/RPC effects\n";

$db=setup(); $wallet=new FakeWallet(); $worker=coordinator($db,$wallet);
check($worker->tick()==='shield-submitted','Shielding did not start');
check(balance($db,1)===0 && balance($db,2)===0,'Reservation not debited exactly');
check(scalar($db,'SELECT COUNT(*) FROM payouts')==2,'Reservation payout evidence missing');
$wallet->opStatus='executing'; check($worker->tick()==='operation-pending','Async op not awaited');
$wallet->opStatus='success'; check($worker->tick()==='broadcast','Shield txid missing');
$wallet->confirmations=5; check($worker->tick()==='confirmations-pending','Immature shielded output was spent');
$wallet->confirmations=6; check($worker->tick()==='shield-confirmed','Shield did not confirm');
$wallet->shieldBalance='0.9999'; check($worker->tick()==='send-submitted','Recipient send not submitted');
$send=array_values(array_filter($wallet->calls,fn($call)=>$call[0]==='z_sendmany'))[0];
check($send[1][0]===config()['pool_zaddress'],'Payout must spend from Sapling source');
check($send[1][1][0]['amount']==='0.09920000' && $send[1][1][1]['amount']==='0.09920000','Recipient amount changed or used floats');
check($worker->tick()==='broadcast','Payout txid not recorded');
check(scalar($db,'SELECT COUNT(*) FROM payouts WHERE completed=1')==0,'Unconfirmed payout marked complete');
check($worker->tick()==='complete','Payout did not complete');
check(scalar($db,'SELECT COUNT(*) FROM payouts WHERE completed=1')==2,'Completed payout count wrong');
check($worker->tick()==='idle' && $wallet->mutations===2,'Completed batch was replayed');
check(scalar($db,'SELECT COUNT(*) FROM zcl_payment_operations')==2,'Operation journal not retained');
$wallet->confirmations=0;
check($worker->tick()==='accounting-held','Completed payout confirmation regression was ignored');
check(scalar($db,'SELECT COUNT(*) FROM zcl_accounting_holds')==1,'Post-completion reorg hold was not durable');
echo "PASS reserve -> mature coinbase shielding -> confirmations -> exact batch payout\n";

$db=setup(); $wallet=new FakeWallet(); $wallet->shieldBalance='1'; $wallet->failMutation=true; $worker=coordinator($db,$wallet);
check($worker->tick()==='held','Ambiguous send was not held');
for($i=0;$i<4;$i++) check($worker->tick()==='held','Ambiguous send was replayed');
check($wallet->mutations===1 && balance($db,1)===0,'Unknown send was retried or refunded');
echo "PASS ambiguous send timeout remains reserved without retry\n";
$db=setup(); $wallet=new FakeWallet(); $wallet->shieldBalance='1';
$failingLedger=new class($db,1) extends ZclPayoutLedger {
    public function recordOperationId(string $id,string $opid): void { throw new RuntimeException('Injected journal-write failure after accepted RPC'); }
};
$worker=new ZclPayoutCoordinator($failingLedger,$wallet,config(),true);
check($worker->tick()==='held' && $wallet->mutations===1,'Accepted RPC with failed journal update was not held');
check(coordinator($db,$wallet)->tick()==='held' && $wallet->mutations===1,'Restart replayed accepted but unrecorded RPC');
echo "PASS accepted RPC followed by journal-write failure cannot replay\n";


$db=setup(); $wallet=new FakeWallet(); $ledger=new ZclPayoutLedger($db,1);
check($ledger->claim(),'Could not claim worker'); $batch=$ledger->reserve(config()+['network'=>'main','minimum_zat'=>5000000,'fee_zat'=>10000,'confirmations'=>6,'coinbase_confirmations'=>101,'max_recipients'=>50,'shield_limit'=>50]);
$ledger->prepareOperation($batch,'send',['method'=>'z_sendmany','params'=>[]]); $ledger->release();
check(coordinator($db,$wallet)->tick()==='held' && $wallet->calls===[],'Process crash intent was replayed');
echo "PASS crash between intent commit and RPC holds without wallet access\n";

$db=setup(); $wallet=new FakeWallet(); $worker=coordinator($db,$wallet); $worker->tick();
$wallet->failRead=true; try { $worker->tick(); throw new RuntimeException('Read failure not surfaced'); } catch(RuntimeException $e) { check($e->getMessage()==='Temporary status-read failure','Unexpected read exception'); }
$wallet->failRead=false; $wallet->loseOperation=true;
check($worker->tick()==='held' && $wallet->mutations===1,'Daemon restart caused duplicate shielding');
echo "PASS read retry is safe; missing daemon operation is held\n";

$db=setup(); $wallet=new FakeWallet(); $worker=coordinator($db,$wallet); $worker->tick(); $worker->tick(); $wallet->confirmations=-1;
check($worker->tick()==='held','Orphaned transaction was not held');
echo "PASS transaction reorg holds reservation\n";

$db=setup(); $wallet=new FakeWallet(); $wallet->utxos[0]['confirmations']=100;
check(coordinator($db,$wallet)->tick()==='waiting-for-funding' && $wallet->mutations===0,'Immature coinbase was shielded');
$db=setup(); $wallet=new FakeWallet(); $wallet->utxos[0]['generated']=false;
check(coordinator($db,$wallet)->tick()==='waiting-for-funding' && $wallet->mutations===0,'Ordinary UTXO was treated as coinbase');
echo "PASS coinbase maturity and generated-output checks\n";
$db=setup(); $wallet=new FakeWallet(); $wallet->shieldBalance='0.1984'; $wallet->utxos=[];
check(coordinator($db,$wallet)->tick()==='waiting-for-funding' && $wallet->mutations===0,'Payout spent miner credit to cover network fee');
check(scalar($db,'SELECT SUM(amount_zat) FROM zcl_payment_items')==19840000,'Unfunded fee changed recipient reservations');
echo "PASS network fee shortage does not reduce miner amounts\n";


$db=setup(); $wallet=new FakeWallet(); $worker=coordinator($db,$wallet);
$wallet->duringMutation=function()use($db){ check(coordinator($db,new FakeWallet())->tick()==='busy','Second worker entered the mutation window'); };
check($worker->tick()==='shield-submitted','First worker could not submit');
check(scalar($db,'SELECT COUNT(*) FROM payouts')==2,'Concurrent worker duplicated reservations');
echo "PASS simultaneous worker lease excludes duplicate reservations\n";

$db=setup(); $wallet=new FakeWallet(); $wallet->utxos=[]; $worker=coordinator($db,$wallet); $worker->tick();
$db->exec("INSERT INTO zcl_accounting_holds VALUES(1,'credited reorg',1)");
$wallet->shieldBalance='1'; check($worker->tick()==='accounting-held' && $wallet->mutations===0,'Accounting hold failed to stop broadcast');
echo "PASS accounting hold stops wallet mutations\n";

$db=setup(); $db->exec('UPDATE accounts SET balance=0');
$db->exec("INSERT INTO blocks (id,category,confirmations) VALUES(1,'generate',100),(2,'orphan',200),(3,'generate',101)");
$db->exec("INSERT INTO earnings VALUES(1,1,1,1,0.00000001,1,0,1),(2,1,1,2,1,1,0,1),(3,1,1,3,0.0992,1,0,1)");
$ledger=new ZclPayoutLedger($db,1);
check($ledger->creditMatureEarnings(time())===1,'Wrong number of mature credits');
check(balance($db,1)===9920000,'Credit lost precision');
check($ledger->creditMatureEarnings(time())===0 && balance($db,1)===9920000,'Credit was replayed');
check(scalar($db,'SELECT status FROM earnings WHERE id=1')==1,'Immature earnings cleared');
$db->exec('UPDATE blocks SET confirmations=101 WHERE id=1');
check($ledger->creditMatureEarnings(time())===1 && balance($db,1)===9920001,'One-zatoshi earning lost');
echo "PASS mature credits are exact, once-only and preserve orphan/immature rows\n";

$db=setup(); $db->exec('UPDATE accounts SET balance=0');
$db->exec("INSERT INTO blocks (id,category,confirmations) VALUES(1,'generate',101)");
$db->exec('INSERT INTO earnings VALUES(1,1,1,1,0.0992,1,0,1)');
$trigger=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? "CREATE TRIGGER fail_credit BEFORE UPDATE ON earnings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected credit failure'" : "CREATE TRIGGER fail_credit BEFORE UPDATE ON earnings BEGIN SELECT RAISE(FAIL,'injected credit failure'); END";
$db->exec($trigger);
try { (new ZclPayoutLedger($db,1))->creditMatureEarnings(time()); throw new RuntimeException('Fault injection did not fire'); }
catch(PDOException $expected) {}
check(balance($db,1)===0 && scalar($db,'SELECT status FROM earnings WHERE id=1')==1,'Partial credit survived rollback');
echo "PASS credit and status update roll back together\n";

// Actual InnoDB concurrency uses separate connections and separate processes.
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' && function_exists('pcntl_fork')) {
    $db=setup(); $db=null;
    $children=[];
    for($i=0;$i<2;$i++) {
        $pid=pcntl_fork();
        if($pid===0) {
            try {
                $child=connect(); $ledger=new ZclPayoutLedger($child,1);
                for($n=0;$n<30;$n++) {
                    if($ledger->claim()) { $ledger->reserve(config()+['minimum_zat'=>5000000,'max_recipients'=>50]); usleep(20000); $ledger->release(); exit(0); }
                    usleep(10000);
                }
                exit(2);
            } catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
        }
        check($pid>0,'Could not fork concurrency test'); $children[]=$pid;
    }
    foreach($children as $pid) { pcntl_waitpid($pid,$status); check(pcntl_wexitstatus($status)===0,'Concurrent worker failed'); }
    $db=connect();
    check(scalar($db,'SELECT COUNT(*) FROM zcl_payment_batches')==1 && scalar($db,'SELECT COUNT(*) FROM payouts')==2,'InnoDB reservation duplicated');
    check(balance($db,1)===0 && balance($db,2)===0,'InnoDB reservation balance incorrect');
    echo "PASS real InnoDB concurrent reservations create one batch\n";
}
echo "All payout ledger tests passed (" . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n";
