<?php
require __DIR__ . '/fixture.php';
require __DIR__ . '/fake-wallet.php';
use app\services\ZclAmount;
use app\services\ZclPayoutLedger;
use app\services\ZclPayoutCoordinator;
function operatorConfig(): array {
    return config()+['operator_enabled'=>true,'operator_taddress'=>'tOperatorTest11111111111111111','operator_reserve_zat'=>1000000,'operator_minimum_zat'=>100000,
        'network'=>'main','minimum_zat'=>5000000,'fee_zat'=>10000,'confirmations'=>6,'coinbase_confirmations'=>101,'max_recipients'=>50,'shield_limit'=>50];
}
function feeFixture(): PDO {
    $db=setup();
    $db->exec("UPDATE accounts SET balance=CASE id WHEN 1 THEN 12.4 ELSE 0 END,is_locked=1");
    $hash=str_repeat('a',64);
    $db->exec("INSERT INTO blocks(id,category,confirmations,coin_id,blockhash) VALUES(1,'generate',101,1,'$hash')");
    $db->exec("INSERT INTO zcl_reward_rounds(block_id,coin_id,blockhash,reward_sat,credited_sat,retained_sat,fee_sat) VALUES(1,1,'$hash',1250000000,1240000000,10000000,10000000)");
    $db->exec("INSERT INTO earnings VALUES(1,1,1,1,12.4,2,0,1)");
    return $db;
}
function operatorWorker(PDO $db,FakeWallet $wallet): ZclPayoutCoordinator {return new ZclPayoutCoordinator(new ZclPayoutLedger($db,1),$wallet,operatorConfig(),true);}
function creditFees(PDO $db): void {$l=new ZclPayoutLedger($db,1);check($l->claim(),'Lease');$l->creditMatureOperatorFees();$l->release();}

foreach ([['operator_enabled'=>'true'],['operator_reserve_zat'=>999999],['operator_minimum_zat'=>1],['fee_zat'=>10000.0]] as $override) {
    $db=setup();$wallet=new FakeWallet();
    try{new ZclPayoutCoordinator(new ZclPayoutLedger($db,1),$wallet,array_replace(operatorConfig(),$override),true);throw new LogicException('Unsafe fee configuration accepted');}catch(RuntimeException $expected){}
    check($wallet->calls===[],'Invalid configuration touched wallet');
}
echo "PASS owner enablement, reserve and amount types fail closed\n";

$db=feeFixture();$wallet=new FakeWallet();$wallet->shieldBalance='12.5';$worker=operatorWorker($db,$wallet);
check($worker->tick()==='send-submitted','Operator send not submitted');
check(balance($db,1)===1240000000,'Locked miner balance changed');
check(scalar($db,'SELECT COUNT(*) FROM payouts')==0,'Owner payment appeared in miner ledger');
check(scalar($db,'SELECT amount_zat FROM zcl_payment_batches')==8990000,'Owner received reserve or network-fee money');
check(scalar($db,'SELECT network_fee_zat FROM zcl_payment_operations')==10000,'Network fee not durably reserved with RPC intent');
$send=array_values(array_filter($wallet->calls,fn($c)=>$c[0]==='z_sendmany'))[0];
check($send[1][1][0]['amount']==='0.08990000','Operator amount lost exact units');
check($send[1][1][0]['address']===operatorConfig()['operator_taddress'],'Wrong operator recipient');
check($worker->tick()==='broadcast' && $worker->tick()==='complete','Operator confirmation protocol failed');
check($worker->tick()==='idle' && $wallet->mutations===1,'Operator fee credit or completed send replayed');
check(scalar($db,'SELECT COUNT(*) FROM zcl_operator_credits')==1,'Fee source journal not once-only');
$wallet->confirmations=0;check($worker->tick()==='accounting-held','Completed operator payout reorg not held');
echo "PASS operator remittance preserves locked miners, reserve, network fees and once-only confirmation journal\n";

$db=feeFixture();$db->exec('UPDATE accounts SET balance=0,is_locked=0');$db->exec('UPDATE accounts SET balance=.1 WHERE id=1');
$wallet=new FakeWallet();$wallet->shieldBalance='12.5';
check(operatorWorker($db,$wallet)->tick()==='send-submitted','Miner send failed');
check(scalar($db,'SELECT purpose FROM zcl_payment_batches')==='miners','Operator jumped ahead of payable miners');
check(scalar($db,'SELECT COUNT(*) FROM zcl_operator_credits')==0,'Operator credit interfered with miner priority');
echo "PASS eligible miner payments take priority\n";

$db=feeFixture();$db->exec("UPDATE blocks SET confirmations=100");$wallet=new FakeWallet();$wallet->shieldBalance='12.5';
check(operatorWorker($db,$wallet)->tick()==='idle' && $wallet->mutations===0,'Immature fees remitted');
$db->exec('UPDATE blocks SET confirmations=101');$db->exec('UPDATE zcl_reward_rounds SET fee_sat=NULL');
check(operatorWorker($db,$wallet)->tick()==='idle','Legacy unknown fee was guessed');
$db->exec('UPDATE zcl_reward_rounds SET fee_sat=10000000,retained_sat=15000000,credited_sat=1235000000');
$db->exec('UPDATE accounts SET balance=12.35 WHERE id=1');$db->exec('UPDATE earnings SET amount=12.35 WHERE id=1');
check(operatorWorker($db,$wallet)->tick()==='send-submitted','Mature fee remittance missing');
check(scalar($db,'SELECT amount_zat FROM zcl_operator_credits')==10000000,'Donation was claimed as operator fee');
echo "PASS only known mature fees are credited; donations and unknown history are excluded\n";

$db=feeFixture();$wallet=new FakeWallet();$wallet->shieldBalance='12.41';
check(operatorWorker($db,$wallet)->tick()==='idle' && $wallet->mutations===0,'Miner backing or reserve was spent');
$db->exec('UPDATE accounts SET balance=12.0 WHERE id=1');$db->exec("INSERT INTO earnings VALUES(2,1,1,1,.4,0,0,1)");
check(operatorWorker($db,$wallet)->tick()==='idle' && $wallet->mutations===0,'Immature miner earnings were ignored');
$db->exec('UPDATE earnings SET status=2 WHERE id=2');$db->exec("INSERT INTO payouts(account_id,idcoin,time,completed,amount,fee,tx) VALUES(1,1,1,0,.4,0,'known-but-unconfirmed')");
check(operatorWorker($db,$wallet)->tick()==='idle' && $wallet->mutations===0,'Unconfirmed payout liabilities were ignored');
echo "PASS all balances, immature earnings and pending payouts remain backed\n";

$db=feeFixture();creditFees($db);$ledger=new ZclPayoutLedger($db,1);check($ledger->claim(),'Lease');
$batch=$ledger->reserveOperator(operatorConfig(),1250000000);check($batch!==null,'Owner reservation missing');$ledger->release();
$db->exec("INSERT INTO earnings VALUES(2,1,1,1,.1,0,0,1)");$wallet=new FakeWallet();$wallet->shieldBalance='12.5';
check(operatorWorker($db,$wallet)->tick()==='operator-waiting-for-surplus','Changed miner liabilities not respected');
check($wallet->mutations===0 && scalar($db,'SELECT state FROM zcl_payment_batches')==='cancelled','Unsent owner reservation not safely released');
check(scalar($db,'SELECT active_batch FROM zcl_payment_control')===null,'Unsent owner batch blocked miners');
echo "PASS newly accrued miner liabilities cancel only an unattempted owner reservation\n";

$db=feeFixture();$wallet=new FakeWallet();$wallet->shieldBalance='12.5';$wallet->failMutation=true;$worker=operatorWorker($db,$wallet);
check($worker->tick()==='held','Ambiguous owner send not held');
for($i=0;$i<3;$i++)check($worker->tick()==='held','Unknown owner send resumed');
check($wallet->mutations===1 && scalar($db,'SELECT amount_zat FROM zcl_payment_batches')==8990000,'Unknown owner payment retried or refunded');
$ledger=new ZclPayoutLedger($db,1);check($ledger->claim(),'Lease');
try{$ledger->cancelUnsentOperator($ledger->active());throw new LogicException('Unknown transfer cancelled');}catch(RuntimeException $expected){}finally{$ledger->release();}
echo "PASS ambiguous owner send retains its reservation and cannot be cancelled/retried\n";

$db=feeFixture();creditFees($db);$db->exec("UPDATE blocks SET category='orphan'");$wallet=new FakeWallet();$wallet->shieldBalance='12.5';
check(operatorWorker($db,$wallet)->tick()==='accounting-held' && $wallet->mutations===0,'Reorged fee source was remitted');
check(scalar($db,'SELECT COUNT(*) FROM zcl_accounting_holds')==1,'Fee-source reorg hold was not durable');
echo "PASS source-block reorg keeps credit evidence and places a durable accounting hold\n";

$db=feeFixture();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?"CREATE TRIGGER fail_fee BEFORE INSERT ON zcl_operator_credits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected fee failure'":"CREATE TRIGGER fail_fee BEFORE INSERT ON zcl_operator_credits BEGIN SELECT RAISE(FAIL,'injected fee failure'); END");
$wallet=new FakeWallet();$wallet->shieldBalance='12.5';
try{operatorWorker($db,$wallet)->tick();throw new LogicException('Injected failure missing');}catch(PDOException $expected){}
check(scalar($db,'SELECT COUNT(*) FROM zcl_operator_credits')==0 && scalar($db,'SELECT COUNT(*) FROM zcl_payment_batches')==0 && $wallet->mutations===0,'Partial fee credit survived rollback');
echo "PASS fee credit rollback cannot authorize a payment\n";
$db=feeFixture();
$db->exec("INSERT INTO zcl_payment_batches(id,coin_id,state,amount_zat,config_json,created_at,updated_at) VALUES('previous',1,'complete',0,'{}',1,1)");
$db->exec("INSERT INTO zcl_payment_operations(id,batch_id,kind,state,request_json,network_fee_zat,created_at,updated_at) VALUES('cost','previous','shield','confirmed','{}',20000,1,1)");
$wallet=new FakeWallet();$wallet->shieldBalance='12.5';check(operatorWorker($db,$wallet)->tick()==='send-submitted','Operator payout after expenses missing');
check(scalar($db,"SELECT amount_zat FROM zcl_payment_batches WHERE purpose='operator'")==8970000,'Prior network fees were not deducted from operator proceeds');
echo "PASS all earlier network fees are deducted before operator proceeds\n";

$db=feeFixture();
$db->exec("INSERT INTO zcl_payment_batches(id,coin_id,state,amount_zat,config_json,created_at,updated_at) VALUES('previous',1,'complete',0,'{}',1,1)");
$db->exec("INSERT INTO zcl_payment_operations(id,batch_id,kind,state,request_json,created_at,updated_at) VALUES('unknown-cost','previous','shield','confirmed','{}',1,1)");
$wallet=new FakeWallet();$wallet->shieldBalance='12.5';
try{operatorWorker($db,$wallet)->tick();throw new LogicException('Unknown historical costs ignored');}catch(RuntimeException $expected){}
check($wallet->mutations===0,'Unknown historical cost authorized operator payout');
echo "PASS unpriced historical operations block owner remittance without guessing costs\n";

if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' && function_exists('pcntl_fork')) {
    $db=feeFixture();$db=null;$children=[];
    for($i=0;$i<2;$i++) {
        $pid=pcntl_fork();
        if($pid===0) {
            try {
                $child=connect();$ledger=new ZclPayoutLedger($child,1);
                for($attempt=0;$attempt<40;$attempt++) {
                    if($ledger->claim()) {
                        $ledger->creditMatureOperatorFees();
                        $ledger->reserveOperator(operatorConfig(),1250000000);
                        usleep(20000);$ledger->release();exit(0);
                    }
                    usleep(10000);
                }
                exit(2);
            } catch(Throwable $e) {fwrite(STDERR,$e->getMessage()."\n");exit(3);}
        }
        check($pid>0,'Fork failed');$children[]=$pid;
    }
    foreach($children as $pid) {pcntl_waitpid($pid,$status);check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'Concurrent operator worker failed');}
    $db=connect();
    check(scalar($db,'SELECT COUNT(*) FROM zcl_operator_credits')==1,'Concurrent workers duplicated fee credit');
    check(scalar($db,"SELECT COUNT(*) FROM zcl_payment_batches WHERE purpose='operator'")==1,'Concurrent workers duplicated operator reservation');
    check(balance($db,1)===1240000000,'Concurrent operator worker altered miner credit');
    echo "PASS real InnoDB concurrent owner workers commit one credit and one reservation\n";
}

echo 'All operator fee tests passed (' . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n";
