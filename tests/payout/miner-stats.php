<?php
require __DIR__.'/../../yiimp2/services/ZclAmount.php';
require __DIR__.'/../../yiimp2/services/ZclMinerStats.php';
use app\services\ZclMinerStats;

$checks=0;
function eq($a,$b,$label): void { global $checks; ++$checks; if ($a!==$b) throw new RuntimeException($label.': '.json_encode([$a,$b])); }
function throws(callable $fn,$label): void { global $checks; ++$checks; try {$fn();} catch (Throwable $e) {return;} throw new RuntimeException($label); }
function fixtureAddress(int $number,int $prefix=0xb8): string {
    $raw="\x1c".chr($prefix).str_repeat(chr($number),20);
    $raw.=substr(hash('sha256',hash('sha256',$raw,true),true),0,4);
    $digits=[0];
    foreach (str_split($raw) as $byte) {
        $carry=ord($byte);
        foreach ($digits as &$digit) {$carry+=$digit*256;$digit=$carry%58;$carry=intdiv($carry,58);} unset($digit);
        while($carry) {$digits[]=$carry%58;$carry=intdiv($carry,58);}
    }
    $alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    return implode('',array_map(static fn($digit)=>$alphabet[$digit],array_reverse($digits)));
}
$address=fixtureAddress(1);$other=fixtureAddress(2);$now=2000000000;
function database(): PDO {
    global $address,$other,$now;
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE coins(id INTEGER,symbol TEXT);
        CREATE TABLE accounts(id INTEGER,coinid INTEGER,username TEXT,balance TEXT,payout_threshold TEXT,is_locked INTEGER);
        CREATE TABLE shares(coinid INTEGER,algo TEXT,valid INTEGER,time INTEGER,difficulty REAL,userid INTEGER);
        CREATE TABLE zcl_accounting_holds(coin_id INTEGER);
        CREATE TABLE blocks(id INTEGER,coin_id INTEGER,category TEXT,confirmations INTEGER,blockhash TEXT);
        CREATE TABLE earnings(userid INTEGER,coinid INTEGER,blockid INTEGER,amount TEXT,status INTEGER);
        CREATE TABLE zcl_reward_rounds(block_id INTEGER,coin_id INTEGER,blockhash TEXT);
        CREATE TABLE zcl_payment_batches(id TEXT,coin_id INTEGER,state TEXT,purpose TEXT);
        CREATE TABLE zcl_payment_items(batch_id TEXT,account_id INTEGER,payout_id INTEGER,amount_zat TEXT,address TEXT);
        CREATE TABLE payouts(id INTEGER,account_id INTEGER,idcoin INTEGER,amount TEXT,completed INTEGER,tx TEXT);
        CREATE TABLE zcl_payment_operations(batch_id TEXT,kind TEXT,state TEXT,txid TEXT);');
    $db->exec("INSERT INTO coins VALUES(1,'ZCL'),(2,'ZEC');
        INSERT INTO blocks VALUES(1,1,'generate',101,'a'),(2,1,'immature',50,'b'),(3,1,'generate',101,'c'),(4,1,'orphan',-1,'d');
        INSERT INTO zcl_reward_rounds VALUES(1,1,'a'),(2,1,'b'),(3,1,'c'),(4,1,'d');
        INSERT INTO earnings VALUES(1,1,1,'0.11',2),(1,1,2,'0.03',0),(1,1,3,'0.02',1),(1,1,4,'100',-1),(2,1,1,'8',2);
        INSERT INTO zcl_payment_batches VALUES('paid',1,'complete','miners'),('pending',1,'confirming','miners'),('owner',1,'complete','operator');
        INSERT INTO zcl_payment_items VALUES('paid',1,1,'2000000','$address'),('pending',1,2,'5000000','$address');
        INSERT INTO payouts VALUES(1,1,1,'0.02',1,'".str_repeat('a',64)."'),(2,1,1,'0.05',0,NULL);
        INSERT INTO zcl_payment_operations VALUES('paid','shield','confirmed',NULL),('paid','send','confirmed','".str_repeat('a',64)."'),('pending','send','broadcast',NULL);");
    $q=$db->prepare('INSERT INTO accounts VALUES(?,?,?,?,?,?)');
    $q->execute([1,1,$address,'0.04','0.1',0]);$q->execute([2,1,$other,'0',null,0]);
    $q=$db->prepare('INSERT INTO shares VALUES(?,?,?,?,?,?)');
    foreach ([[1,'equihash192',1,$now-299,0.0000390625,1],[1,'equihash192',1,$now-300,0.0000390625,1],
        [1,'equihash192',1,$now-3599,0.000078125,1],[1,'equihash192',1,$now-3600,99,1],
        [1,'equihash192',0,$now-2,99,1],[2,'equihash192',1,$now-2,99,1],
        [1,'equihash192',1,$now-2,0.0000390625,2],[1,'equihash192',1,$now+1,99,1]] as $row) $q->execute($row);
    return $db;
}
$node=['schemaVersion'=>1,'asset'=>'ZCL','generatedAt'=>gmdate('c',$now),'node'=>['synced'=>true],'mining'=>['networkSolps'=>10965]];
function report(PDO $db,?string $address): array { global $node,$now; $db->exec('PRAGMA query_only=ON'); return (new ZclMinerStats($db))->snapshot($address,$now,$node,null,5000000); }
eq($address,ZclMinerStats::address($address),'t1 checksum');
eq(fixtureAddress(3,0xbd),ZclMinerStats::address(fixtureAddress(3,0xbd)),'t3 checksum');
foreach (['',strtolower($address),substr($address,0,-1).'0',substr($address,0,-1).($address[-1]==='1'?'2':'1'),$address.'x'] as $bad) throws(static fn()=>ZclMinerStats::address($bad),'invalid address rejected');
$db=database();$result=report($db,$address);$miner=$result['miner'];
foreach (['earnedZat'=>'16000000','creditedZat'=>'11000000','availableZat'=>'4000000','immatureZat'=>'3000000',
    'awaitingCreditZat'=>'2000000','paidZat'=>'2000000','pendingPayoutZat'=>'5000000','thresholdZat'=>'10000000','remainingZat'=>'6000000'] as $key=>$expected) eq($expected,$miner[$key],$key);
eq(40,$miner['progressPercent'],'balance progress uses only unreserved balance');
eq(false,$miner['thresholdReached'],'threshold not reached');
eq('ok',$miner['status'],'known account');
eq(0.0000390625,$miner['work']['last5m']['difficultyWeight'],'5m lower boundary excluded');
eq(0.00015625,$miner['work']['lastHour']['difficultyWeight'],'hour excludes rejected,othercoin,future and older work');
eq(0.000078125,$result['pool']['work']['last5m']['difficultyWeight'],'pool includes both accounts');
eq(true,abs($miner['work']['last5m']['estimatedSolps']-0.00853346354365)<1e-10,'native Equihash scaling');
eq(true,$miner['work']['last5m']['networkPercent']>0,'network fraction available');
eq('retained-pool-ledger',$result['accountingBasis'],'retention scope explicit');
eq(false,$result['history']['available'],'no fabricated historical credit timestamp');
eq(null,report(database(),null)['miner'],'pool-only request');
$missing=report(database(),fixtureAddress(9))['miner'];
eq('not-found',$missing['status'],'new valid address not-found');
eq('0',$missing['availableZat'],'new address verified zero');
eq('5000000',$missing['thresholdZat'],'default payout threshold');
eq(0.0,$missing['work']['lastHour']['estimatedSolps'],'no recorded shares zero');
eq(true,!str_contains(json_encode($result),'account_id')&&!str_contains(json_encode($result),'batch_id'),'internal IDs never exposed');
foreach ([['asset'=>'ZEC'],['schemaVersion'=>2],['generatedAt'=>gmdate('c',$now+61)],['mining'=>['networkSolps'=>0]],['mining'=>['networkSolps'=>'10965']]] as $patch) {
    eq('unavailable',ZclMinerStats::network(array_replace($node,$patch),$now)['status'],'invalid network metadata rejected');
}
$stale=$node;$stale['generatedAt']=gmdate('c',$now-181);
$staleResult=(new ZclMinerStats(database()))->snapshot($address,$now,$stale,null,5000000);
eq('stale',$staleResult['network']['status'],'old network is stale');
eq(null,$staleResult['miner']['work']['last5m']['networkPercent'],'stale denominator never used');
foreach (["UPDATE blocks SET category='orphan',confirmations=-1 WHERE id=1", "UPDATE blocks SET confirmations=100 WHERE id=3", "UPDATE blocks SET category='generate' WHERE id=2"] as $change) {
    $db=database();$db->exec($change);$r=report($db,$address);
    eq('partial',$r['miner']['status'],'inconsistent earning state partial');
}
$db=database();$db->exec('INSERT INTO zcl_accounting_holds VALUES(1)');$r=report($db,$address);
eq(true,$r['miner']['accountingHeld'],'accounting hold exposed');eq('partial',$r['status'],'hold status partial');
$db=database();$db->exec("UPDATE zcl_payment_batches SET state='held' WHERE id='pending'");$r=report($db,$address);
eq('5000000',$r['miner']['pendingPayoutZat'],'held reservation remains in flight');eq('partial',$r['miner']['status'],'held payment partial');
$db=database();$db->exec("DELETE FROM zcl_payment_operations WHERE kind='send' AND batch_id='paid'");
throws(static fn()=>report($db,$address),'shield-only confirmation is not payment');
$db=database();$db->exec("UPDATE payouts SET completed=0 WHERE id=1");throws(static fn()=>report($db,$address),'complete batch inconsistent payout rejected');
$db=database();$db->exec("UPDATE payouts SET amount='0.03' WHERE id=1");throws(static fn()=>report($db,$address),'payment amount mismatch rejected');
$db=database();$db->exec('UPDATE accounts SET is_locked=1 WHERE id=1');eq(true,report($db,$address)['miner']['payoutLocked'],'account lock exposed');
foreach (["DELETE FROM zcl_payment_items WHERE payout_id=1", "DELETE FROM payouts WHERE id=1",
    "DELETE FROM zcl_payment_batches WHERE id='paid'", "UPDATE zcl_payment_batches SET state='cancelled' WHERE id='pending'",
    "UPDATE payouts SET completed=2 WHERE id=2", "UPDATE payouts SET tx='wrong' WHERE id=1",
    "UPDATE zcl_payment_items SET address='wrong' WHERE payout_id=1",
    "INSERT INTO zcl_payment_operations VALUES('paid','send','confirmed','".str_repeat('a',64)."')"] as $change) {
    $db=database();$db->exec($change);throws(static fn()=>report($db,$address),'broken payment evidence never silently omitted');
}
echo "Miner statistics: {$checks} checks passed.\n";
