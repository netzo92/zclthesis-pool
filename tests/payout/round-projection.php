<?php
require __DIR__.'/../../yiimp2/services/ZclAmount.php';
require __DIR__.'/../../yiimp2/services/ZclMinerStats.php';
use app\services\ZclRoundProjection;
use app\services\ZclMinerStats;
use app\services\ZclTreasuryStats;

$checks=0; $now=2000000000; $address=ZclTreasuryStats::RECIPIENT;
function eq($expected,$actual,string $label): void {
    global $checks; ++$checks;
    if ($expected!==$actual) throw new RuntimeException($label.': '.json_encode([$expected,$actual]));
}
function node(): array {
    global $now;
    $at=gmdate('Y-m-d\TH:i:s\Z',$now-30);$hash=str_repeat('a',64);
    return ['schemaVersion'=>1,'asset'=>'ZCL','source'=>'https://pool.zclthesis.com','generatedAt'=>$at,
        'node'=>['synced'=>true],'chain'=>['height'=>3192878,'hash'=>$hash,'blockAt'=>$at],
        'mining'=>['networkSolps'=>10000,'nextBlockSubsidy'=>['schemaVersion'=>1,'asset'=>'ZCL','status'=>'ok','generatedAt'=>$at,
            'tipHeight'=>3192878,'tipHash'=>$hash,'height'=>3192879,'subsidyZat'=>'31250000',
            'basis'=>'next-height-subsidy-excluding-transaction-fees']]];
}
function database(): PDO {
    global $address,$now;
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE coins(id INTEGER PRIMARY KEY,symbol TEXT,algo TEXT,auto_exchange INTEGER);
        CREATE TABLE accounts(id INTEGER PRIMARY KEY,coinid INTEGER,username TEXT,balance TEXT,payout_threshold TEXT,is_locked INTEGER,no_fees INTEGER,donation TEXT);
        CREATE TABLE shares(id INTEGER PRIMARY KEY,coinid INTEGER,algo TEXT,valid INTEGER,time INTEGER,difficulty TEXT,userid INTEGER,blocknumber INTEGER,blockrewarded INTEGER,solo INTEGER);
        CREATE TABLE zcl_accounting_holds(coin_id INTEGER);
        CREATE TABLE blocks(id INTEGER PRIMARY KEY,coin_id INTEGER,category TEXT,confirmations INTEGER,blockhash TEXT,height INTEGER,time INTEGER,amount TEXT);
        CREATE TABLE earnings(userid INTEGER,coinid INTEGER,blockid INTEGER,amount TEXT,status INTEGER);
        CREATE TABLE zcl_reward_rounds(block_id INTEGER,coin_id INTEGER,blockhash TEXT,created_at INTEGER,reward_sat TEXT,credited_sat TEXT,retained_sat TEXT,fee_sat TEXT);
        CREATE TABLE zcl_payment_batches(id TEXT,coin_id INTEGER,state TEXT,purpose TEXT,operator_address TEXT,amount_zat TEXT);
        CREATE TABLE zcl_payment_items(batch_id TEXT,account_id INTEGER,payout_id INTEGER,amount_zat TEXT,address TEXT);
        CREATE TABLE payouts(id INTEGER,account_id INTEGER,idcoin INTEGER,amount TEXT,completed INTEGER,tx TEXT);
        CREATE TABLE zcl_payment_operations(batch_id TEXT,kind TEXT,state TEXT,txid TEXT);');
    $db->exec("INSERT INTO coins VALUES(1,'ZCL','equihash192',0),(2,'ZEC','equihash',0);
        INSERT INTO accounts VALUES(1,1,'$address','0.04000000','0.10000000',0,0,'0'),(2,1,'other-address','0',NULL,0,0,'0');");
    $q=$db->prepare('INSERT INTO shares VALUES(?,?,?,?,?,?,?,?,?,?)');
    // Initial retained round spans much more than an hour. All eligible work counts.
    $q->execute([1,1,'equihash192',1,$now-7200,'0.250000000000000000000000',1,3192800,null,0]);
    $q->execute([2,1,'equihash192',1,$now-3,'0.750000000000000000000000',2,3192879,null,null]);
    return $db;
}
function report(PDO $db,?array $source=null,?int $account=1,bool $held=false,bool $consistent=true): array {
    global $now,$address;
    $db->exec('PRAGMA query_only=ON');
    if (!$db->inTransaction()) $db->beginTransaction();
    $before=$db->query('SELECT total_changes()')->fetchColumn();
    $result=(new ZclRoundProjection($db))->snapshot(1,$account===null?'new-address':$address,$account,$now,$source ?? node(),$held,$consistent);
    eq($before,$db->query('SELECT total_changes()')->fetchColumn(),'projection never changes any row');
    return $result;
}
function unavailable(array $result,string $reason): void {
    eq('unavailable',$result['status'],'uncertain projection unavailable');eq($reason,$result['reason'],'reason');
    foreach (['sourceGeneratedAt','tipHeight','tipHash','height','roundId','subsidyZat','feePercent','poolWeight','addressWeight',
        'sharePercent','grossZat','poolFeeZat','additionalDonationZat','allocationZat'] as $key) eq(null,$result[$key],'unavailable '.$key);
}
$r=report(database());
eq('ok',$r['status'],'initial retained round available');eq(null,$r['reason'],'no failure reason');
eq($address,$r['address'],'selected address explicitly bound');eq('initial',$r['roundId'],'initial pool round identity');
eq('1.000000000000000000000000',$r['poolWeight'],'all retained eligible work, not only last hour');
eq('0.250000000000000000000000',$r['addressWeight'],'exact selected work');eq(25.0,$r['sharePercent'],'share percentage');
foreach (['grossZat'=>'7812500','poolFeeZat'=>'62500','additionalDonationZat'=>'0','allocationZat'=>'7750000','feePercent'=>'0.8'] as $key=>$value) eq($value,$r[$key],$key);
eq(false,str_contains(json_encode($r),'account_id')||str_contains(json_encode($r),'other-address'),'no private identifiers or other accounts');

// Integrated endpoint shape and existing earned/balance/threshold accounting stay independent.
$db=database();$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
$canonical=['schemaVersion'=>1,'asset'=>'ZCL','generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'status'=>'ok'];
$api=(new ZclMinerStats($db))->snapshot($address,$now,node(),$canonical,5000000);
eq($r,$api['miner']['projection'],'existing endpoint exposes exact projection contract');
eq('0',$api['miner']['earnedZat'],'projection is not earned money');eq('4000000',$api['miner']['availableZat'],'projection does not increase spendable balance');
eq('10000000',$api['miner']['thresholdZat'],'projection does not change payout threshold');eq(40,$api['miner']['progressPercent'],'payout progress unchanged');
eq('0',$api['pool']['treasury']['received']['totalZat'],'projection is not a treasury receipt');
if (($argv[1] ?? null)==='--fixture') {echo json_encode($api,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit;}

foreach ([null,array_replace($canonical,['status'=>'partial']),array_replace($canonical,['generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now-181)])] as $source) {
    $db=database();$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    $uncertain=(new ZclMinerStats($db))->snapshot($address,$now,node(),$source,5000000);
    unavailable($uncertain['miner']['projection'],'inconsistent-ledger');
    eq('4000000',$uncertain['miner']['availableZat'],'canonical uncertainty does not alter account balance');
}

// Noneligible work cannot alter the result. Already-consumed rows do not return after an orphan.
$db=database();$q=$db->prepare('INSERT INTO shares VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach ([[3,1,'equihash192',0,$now-2,'999',1,3192879,null,0],[4,2,'equihash192',1,$now-2,'999',1,3192879,null,0],
    [5,1,'other',1,$now-2,'999',1,3192879,null,0],[6,1,'equihash192',1,$now-2,'999',1,3192879,3192878,0],
    [7,1,'equihash192',1,$now-2,'999',1,3192879,null,1]] as $row) $q->execute($row);
eq($r,report($db),'invalid, other coin/algo, consumed and solo rows excluded');
$zero=report(database(),null,null);eq('ok',$zero['status'],'new account with no work in known round is known zero');
foreach (['grossZat','poolFeeZat','additionalDonationZat','allocationZat'] as $key) eq('0',$zero[$key],'zero-work '.$key);
eq('0.000000000000000000000000',$zero['addressWeight'],'zero exact weight');
$db=database();$db->exec('DELETE FROM shares');unavailable(report($db),'no-round-work');

// Fee is calculated once per round, then split with the same largest-remainder allocator.
$db=database();$db->exec("UPDATE shares SET difficulty='1'; INSERT INTO accounts VALUES(3,1,'third','0',NULL,0,0,'0');
    INSERT INTO shares VALUES(3,1,'equihash192',1,".($now-1).",'1',3,3192879,NULL,0); UPDATE accounts SET donation='1' WHERE id=1");
$round=report($db);
eq('10416667',$round['grossZat'],'stable user-ID tie earns largest-remainder zatoshi');
eq('83334',$round['poolFeeZat'],'fee has its own largest-remainder tie');
eq('103333',$round['additionalDonationZat'],'account donation is floored after fee');
eq('10230000',$round['allocationZat'],'net exact allocation with both deductions');
eq($round['grossZat'],bcadd(bcadd($round['allocationZat'],$round['poolFeeZat'],0),$round['additionalDonationZat'],0),'individual exact conservation');
$db=database();$db->exec("UPDATE accounts SET no_fees=1,donation='10' WHERE id=1");$exempt=report($db);
eq('0',$exempt['poolFeeZat'],'fee exemption respected');eq('781250',$exempt['additionalDonationZat'],'donation still applies to fee-exempt account');eq('7031250',$exempt['allocationZat'],'fee-exempt net');
$db=database();$db->exec('UPDATE accounts SET no_fees=NULL,donation=NULL');eq($r,report($db),'allocator nullable false/empty values preserved');
$db=database();$db->exec("UPDATE accounts SET donation='100' WHERE id=1");eq('0',report($db)['allocationZat'],'full account donation leaves zero allocation');
$tiny=node();$tiny['mining']['nextBlockSubsidy']['subsidyZat']='1';$small=report(database(),$tiny);eq('0',$small['grossZat'],'sub-zatoshi proportional share can round to zero');
$db=database();$db->exec("UPDATE shares SET difficulty='0.000000000000000000000001'");$small=report($db);eq('0.000000000000000000000002',$small['poolWeight'],'smallest allocator decimal precision preserved');eq('15500000',$small['allocationZat'],'tiny work retains proportional allocation');

// The pool's allocation identity changes only when its own next round is journaled.
$hash=str_repeat('b',64);$db=database();
$db->exec("INSERT INTO blocks VALUES(1,1,'generate',101,'$hash',3192000,".($now-10000).",'1');
    INSERT INTO zcl_reward_rounds VALUES(1,1,'$hash',".($now-9900).",'100000000','99200000','800000','800000')");
eq($hash,report($db)['roundId'],'last allocated pool block identifies current round');
$db=database();$db->exec("INSERT INTO blocks VALUES(1,1,'orphan',-1,'$hash',3192000,".($now-10000).",'1');
    INSERT INTO zcl_reward_rounds VALUES(1,1,'$hash',".($now-9900).",'100000000','99200000','800000','800000')");
eq($hash,report($db)['roundId'],'uncredited orphan allocation still consumed its old work');
$advanced=node();$advanced['chain']['height']++;$advanced['chain']['hash']=str_repeat('c',64);
$advanced['mining']['nextBlockSubsidy']['tipHeight']++;$advanced['mining']['nextBlockSubsidy']['height']++;$advanced['mining']['nextBlockSubsidy']['tipHash']=$advanced['chain']['hash'];
eq('initial',report(database(),$advanced)['roundId'],'network block alone does not reset pool round');
foreach (['new','immature','generate'] as $category) {
    $db=database();$db->exec("INSERT INTO blocks VALUES(1,1,'$category',1,'$hash',3192879,$now,'1')");unavailable(report($db),'pending-pool-block');
}
$db=database();$db->exec("INSERT INTO zcl_reward_rounds VALUES(1,1,'$hash',$now,'1','1','0','0')");unavailable(report($db),'inconsistent-ledger');
$db=database();$db->exec('INSERT INTO zcl_accounting_holds VALUES(1)');unavailable(report($db),'accounting-held');
unavailable(report(database(),null,1,true),'accounting-held');unavailable(report(database(),null,1,false,false),'inconsistent-ledger');
$outside=(new ZclRoundProjection(database()))->snapshot(1,$address,1,$now,node(),false,true);unavailable($outside,'snapshot-unavailable');

foreach ([['schemaVersion',2],['asset','ZEC'],['source','https://untrusted.example']] as [$key,$value]) {$n=node();$n[$key]=$value;unavailable(report(database(),$n),'invalid-node-context');}
foreach (['schemaVersion'=>2,'asset'=>'ZEC','status'=>'unavailable','tipHeight'=>3192877,'tipHash'=>str_repeat('d',64),'height'=>3192878,
    'basis'=>'current-height','subsidyZat'=>'0'] as $key=>$value) {$n=node();$n['mining']['nextBlockSubsidy'][$key]=$value;unavailable(report(database(),$n),'invalid-node-context');}
foreach ([1,'-1','1.0','2100000000000001','1e8','01'] as $value) {$n=node();$n['mining']['nextBlockSubsidy']['subsidyZat']=$value;unavailable(report(database(),$n),'invalid-node-context');}
foreach ([$now-181,$now+2] as $time) {$n=node();$n['generatedAt']=$n['mining']['nextBlockSubsidy']['generatedAt']=gmdate('Y-m-d\TH:i:s\Z',$time);unavailable(report(database(),$n),'stale-node-context');}
$n=node();$n['mining']['nextBlockSubsidy']['generatedAt']=gmdate('Y-m-d\TH:i:s\Z',$now);unavailable(report(database(),$n),'stale-node-context');
$n=node();$n['node']['synced']=false;unavailable(report(database(),$n),'node-not-current');
$n=node();$n['chain']['blockAt']=gmdate('Y-m-d\TH:i:s\Z',$now-1801);unavailable(report(database(),$n),'node-not-current');
$n=node();$n['generatedAt']=$n['mining']['nextBlockSubsidy']['generatedAt']='2033-02-30T00:00:00Z';unavailable(report(database(),$n),'invalid-node-context');
foreach ([['time',$now+1,'future-round-work'],['blocknumber',3192880,'future-round-work'],['blocknumber',null,'inconsistent-ledger'],
    ['userid',99,'inconsistent-ledger'],['difficulty','0','invalid-round-work'],['difficulty','-1','invalid-round-work'],
    ['difficulty','0.0000000000000000000000001','invalid-round-work']] as [$column,$value,$reason]) {
    $db=database();$q=$db->prepare('UPDATE shares SET '.$column.'=? WHERE id=1');$q->execute([$value]);unavailable(report($db),$reason);
}
foreach (["UPDATE accounts SET coinid=2 WHERE id=1","UPDATE accounts SET username='wrong' WHERE id=1","UPDATE accounts SET username='$address' WHERE id=2",
    'UPDATE accounts SET no_fees=2 WHERE id=1','UPDATE coins SET auto_exchange=1 WHERE id=1'] as $sql) {$db=database();$db->exec($sql);unavailable(report($db),'inconsistent-ledger');}
$db=database();$db->exec("UPDATE accounts SET donation='101' WHERE id=1");unavailable(report($db),'invalid-fee-configuration');
$db=database();$db->exec('DROP TABLE shares');unavailable(report($db),'inconsistent-ledger');
$db=database();$db->beginTransaction();$q=$db->prepare('INSERT INTO shares VALUES(?,1,?,1,?,?,1,3192879,NULL,0)');
for ($i=3;$i<=ZclRoundProjection::MAX_ROWS+1;$i++) $q->execute([$i,'equihash192',$now-7200,'1']);$db->commit();unavailable(report($db),'row-limit');
define('YIIMP_FEES_MINING','1');unavailable(report(database()),'invalid-fee-configuration');
echo "Round projection: {$checks} checks passed.\n";
