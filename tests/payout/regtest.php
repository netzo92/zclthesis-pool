<?php
require __DIR__ . '/fixture.php';
require __DIR__ . '/../../yiimp2/services/ZclWalletRPC.php';
use app\services\ZclAmount;
use app\services\ZclPayoutLedger;
use app\services\ZclPayoutCoordinator;
use app\services\ZclWalletRPC;

$dir=getenv('ZCL_TEST_DATADIR');
$cli=getenv('ZCLCLI');
if(!$dir || !preg_match('#^/tmp/zcl-payout-regtest\.[A-Za-z0-9]+$#D',$dir) || !$cli) throw new RuntimeException('Run the isolated regtest.sh wrapper');
function testRpc(string $method,...$args) {
    global $cli,$dir;
    $command=[$cli,'-regtest','-datadir='.$dir,$method];
    foreach($args as $arg) $command[]=is_array($arg)?json_encode($arg,JSON_THROW_ON_ERROR):(string)$arg;
    $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code=proc_close($proc);
    if($code!==0) throw new RuntimeException($method.': '.trim($err));
    $parsed=json_decode($out,true);
    return json_last_error()===JSON_ERROR_NONE?$parsed:trim($out);
}
$chain=testRpc('getblockchaininfo');
check(($chain['chain']??'')==='regtest' && ($chain['blocks']??-1)===0,'Requires a fresh isolated regtest chain');
// The daemon restricts generate to regtest. No persistent mining is enabled.
testRpc('generate',101);
$unspent=testRpc('listunspent',101);
$coinbase=array_values(array_filter($unspent,fn($u)=>($u['generated']??false)===true))[0]??null;
check($coinbase!==null,'No mature synthetic coinbase');
$poolT=$coinbase['address'];
$poolZ=testRpc('z_getnewaddress','sapling');
$recipients=[testRpc('getnewaddress'),testRpc('getnewaddress')];
// This must fail specifically because protected coinbase cannot be paid directly.
$rejected=false;
try { testRpc('sendtoaddress',$recipients[0],'0.01'); }
catch(RuntimeException $e) { $rejected=str_contains(strtolower($e->getMessage()),'coinbase') || str_contains(strtolower($e->getMessage()),'shield'); }
check($rejected,'Protected-coinbase direct spend was not rejected');
$db=setup();
$update=$db->prepare('UPDATE accounts SET username=? WHERE id=?');
foreach($recipients as $index=>$address) $update->execute([$address,$index+1]);
$settings=parse_ini_file($dir.'/zclassic.conf');
$rpc=new ZclWalletRPC('127.0.0.1',(int)$settings['rpcport'],$settings['rpcuser'],$settings['rpcpassword']);
$ledger=new ZclPayoutLedger($db,1);
$worker=new ZclPayoutCoordinator($ledger,$rpc,['network'=>'regtest','pool_taddress'=>$poolT,'pool_zaddress'=>$poolZ],true);
$phases=[]; $confirmed=[]; $complete=false;
for($i=0;$i<240;$i++) {
    $phase=$worker->tick();
    $phases[]=$phase;
    if($phase==='held') throw new RuntimeException('Regtest payout held: '.json_encode($ledger->active()));
    $active=$ledger->active();
    if($active && $active['state']==='confirming') {
        $txid=$active['operation']['txid'];
        if(!isset($confirmed[$txid])) { testRpc('generate',6); $confirmed[$txid]=true; }
    }
    if($phase==='complete') { $complete=true; break; }
    usleep(250000);
}
check($complete,'Regtest payout did not complete within the test deadline');
foreach($recipients as $address) check(ZclAmount::parse(testRpc('getreceivedbyaddress',$address,6))===9920000,'Recipient amount differs from reserved credit');
check(scalar($db,'SELECT COUNT(*) FROM payouts WHERE completed=1')==2,'Payout records were not completed');
check(balance($db,1)===0 && balance($db,2)===0,'Reservation balance changed');
check($worker->tick()==='idle','Completed regtest payout replayed');
$ops=$db->query('SELECT kind,opid,txid,state FROM zcl_payment_operations ORDER BY created_at,id')->fetchAll(PDO::FETCH_ASSOC);
check(count($ops)===2,'Expected exactly one shielding and one send operation');
foreach($ops as $op) {
    $status=testRpc('z_getoperationstatus',[$op['opid']]);
    check(count($status)===1 && $status[0]['status']==='success','Operation evidence was lost or pruned');
    if($op['kind']==='send') {
        $tx=testRpc('gettransaction',$op['txid']);
        $decoded=testRpc('decoderawtransaction',$tx['hex']);
        check(count($decoded['vShieldedSpend']??[])>0,'Recipient transaction did not spend Sapling notes');
    }
}
$report=['passed'=>true,'scope'=>'v2.1.2-beta6 protected-coinbase Sapling regtest, synthetic funds only','network'=>'regtest','height'=>testRpc('getblockcount'),'recipients'=>count($recipients),'amount_each'=>'0.09920000','operations'=>$ops,'phases'=>$phases];
file_put_contents($dir.'/report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
