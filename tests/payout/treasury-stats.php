<?php
require __DIR__.'/../../yiimp2/services/ZclAmount.php';
require __DIR__.'/../../yiimp2/services/ZclMinerStats.php';
use app\services\ZclTreasuryStats;
use app\services\ZclMinerStats;

$checks=0;
function eq($expected,$actual,string $label): void {
    global $checks; ++$checks;
    if ($expected!==$actual) throw new RuntimeException($label.': '.json_encode([$expected,$actual]));
}
$now=2000000000;
$canonical=['schemaVersion'=>1,'asset'=>'ZCL','generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'status'=>'ok'];
function database(bool $filled=true): PDO {
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE coins(id INTEGER,symbol TEXT);
        CREATE TABLE shares(coinid INTEGER,algo TEXT,valid INTEGER,time INTEGER,difficulty REAL,userid INTEGER);
        CREATE TABLE zcl_accounting_holds(coin_id INTEGER);
        CREATE TABLE accounts(id INTEGER,coinid INTEGER,username TEXT);
        CREATE TABLE blocks(id INTEGER,coin_id INTEGER,blockhash TEXT,category TEXT,confirmations INTEGER,amount TEXT);
        CREATE TABLE earnings(userid INTEGER,coinid INTEGER,blockid INTEGER,amount TEXT,status INTEGER);
        CREATE TABLE zcl_reward_rounds(block_id INTEGER,coin_id INTEGER,blockhash TEXT,reward_sat TEXT,credited_sat TEXT,retained_sat TEXT,fee_sat TEXT);
        CREATE TABLE zcl_payment_batches(id TEXT,coin_id INTEGER,state TEXT,purpose TEXT,operator_address TEXT,amount_zat TEXT);
        CREATE TABLE zcl_payment_operations(batch_id TEXT,kind TEXT,state TEXT,txid TEXT);
        CREATE TABLE zcl_payment_items(batch_id TEXT,account_id INTEGER,payout_id INTEGER,address TEXT,amount_zat TEXT);
        CREATE TABLE payouts(id INTEGER,account_id INTEGER,idcoin INTEGER,amount TEXT,completed INTEGER,tx TEXT);');
    $db->exec("INSERT INTO coins VALUES(1,'ZCL'),(2,'ZEC')");
    if (!$filled) return $db;
    $recipient=ZclTreasuryStats::RECIPIENT;
    $a=str_repeat('a',64);$b=str_repeat('b',64);$c=str_repeat('c',64);$d=str_repeat('d',64);$e=str_repeat('e',64);
    $db->exec("INSERT INTO accounts VALUES(1,1,'$recipient'),(2,1,'another-address'),(3,2,'$recipient');
        INSERT INTO blocks VALUES(1,1,'$a','generate',101,'12.50000000'),(2,1,'$b','immature',12,'1.00000000'),(3,1,'$c','orphan',-1,'1.00000000');
        INSERT INTO zcl_reward_rounds VALUES(1,1,'$a','1250000000','1240000000','10000000','10000000'),
            (2,1,'$b','100000000','98208000','1792000','800000'),(3,1,'$c','100000000','99200000','800000','800000');
        INSERT INTO earnings VALUES(1,1,1,'0.99200000',2),(1,1,2,'0.10000000',0),(1,1,3,'0.50000000',-1),(2,1,1,'3.00000000',2),(3,2,1,'2.00000000',2);
        INSERT INTO zcl_payment_batches VALUES('owner',1,'complete','operator','$recipient','8970000'),
            ('miners',1,'complete','miners',NULL,'305000000'),('pending',1,'confirming','operator','$recipient','100000'),
            ('cancelled',1,'cancelled','operator','$recipient','100000'),('elsewhere',1,'complete','operator','another-address','500000000'),
            ('other-coin',2,'complete','operator','$recipient','500000000');
        INSERT INTO zcl_payment_operations VALUES('owner','send','confirmed','$e'),('owner','shield','confirmed',NULL),
            ('miners','send','confirmed','$d'),('pending','send','broadcast',NULL);
        INSERT INTO zcl_payment_items VALUES('miners',1,1,'$recipient','5000000'),('miners',2,2,'another-address','300000000');
        INSERT INTO payouts VALUES(1,1,1,'0.05000000',1,'$d'),(2,2,1,'3.00000000',1,'$d');");
    return $db;
}
function report(PDO $db,bool $held=false,?array $source=null,bool $useDefault=true): array {
    global $now,$canonical;
    $db->exec('PRAGMA query_only=ON');
    $before=$db->query('SELECT total_changes()')->fetchColumn();
    $result=(new ZclTreasuryStats($db))->snapshot(1,$now,$held,$useDefault?$canonical:$source);
    eq($before,$db->query('SELECT total_changes()')->fetchColumn(),'report never mutates database');
    return $result;
}
$r=report(database());
eq('ok',$r['status'],'complete ledger');
eq('zatoshi',$r['unit'],'explicit units');
eq(ZclTreasuryStats::RECIPIENT,$r['recipient'],'fixed approved destination');
eq(['totalZat'=>'10800000','matureZat'=>'10000000','immatureZat'=>'800000'],$r['allocated']['operatorFees'],'exact fee allocations and maturity');
eq(['totalZat'=>'992000','matureZat'=>'0','immatureZat'=>'992000'],$r['allocated']['otherRetained'],'additional retained deductions exclude fee');
eq(['totalZat'=>'109200000','matureZat'=>'99200000','immatureZat'=>'10000000'],$r['allocated']['sharedMining'],'net shared-address allocations');
eq(['operatorTransfersZat'=>'8970000','sharedMiningZat'=>'5000000','totalZat'=>'13970000'],$r['received'],'only confirmed destination transfers count');
eq(1,$r['excludedOrphanRounds'],'orphan source excluded');
eq(null,$r['coverageStartedAt'],'no fabricated coverage date');
eq('confirmed-payout-journal',$r['receivedBasis'],'receipt is journal evidence, not a live wallet balance');
eq(false,str_contains(json_encode($r),'batch_id')||str_contains(json_encode($r),'account_id')||str_contains(json_encode($r),'config_json')||str_contains(json_encode($r),'another-address'),'no identifiers/config/other destinations exposed');
$fullDb=database();
$fullDb->exec("ALTER TABLE accounts ADD balance TEXT DEFAULT '0.94200000'; ALTER TABLE accounts ADD payout_threshold TEXT; ALTER TABLE accounts ADD is_locked INTEGER DEFAULT 0; PRAGMA query_only=ON");
$network=['schemaVersion'=>1,'asset'=>'ZCL','generatedAt'=>gmdate('c',$now),'node'=>['synced'=>true],'mining'=>['networkSolps'=>10000]];
$full=(new ZclMinerStats($fullDb))->snapshot(ZclTreasuryStats::RECIPIENT,$now,$network,$canonical,5000000);
eq($r,$full['pool']['treasury'],'address-scoped API carries exact treasury contract');
eq('99200000',$full['miner']['creditedZat'],'treasury reporting preserves personal ledger figures');
$poolOnly=(new ZclMinerStats($fullDb))->snapshot(null,$now,$network,$canonical,5000000);
eq($r,$poolOnly['pool']['treasury'],'pool-only API carries same treasury contract');
eq(null,$poolOnly['miner'],'pool-only API has no selected miner');
$empty=report(database(false));
eq('ok',$empty['status'],'verified empty ledger');
eq('0',$empty['received']['totalZat'],'verified empty receipts are zero');
eq('0',$empty['allocated']['operatorFees']['totalZat'],'verified empty fees are zero');
$held=report(database(),true);
eq('partial',$held['status'],'hold is partial');eq(true,$held['accountingHeld'],'hold explicit');
eq('13970000',$held['received']['totalZat'],'hold retains confirmed historical transfers');
foreach ([null,array_replace($canonical,['status'=>'partial']),array_replace($canonical,['generatedAt'=>gmdate('c',$now-181)]),array_replace($canonical,['generatedAt'=>gmdate('c',$now+61)])] as $source) {
    eq('partial',report(database(),false,$source,false)['status'],'uncertain canonical coverage cannot be ok');
}
foreach (["UPDATE zcl_reward_rounds SET fee_sat=NULL WHERE block_id=1","UPDATE zcl_reward_rounds SET fee_sat='10000001' WHERE block_id=1"] as $change) {
    $db=database();$db->exec($change);$r=report($db);
    eq('partial',$r['status'],'unknown fee not guessed');
    eq('800000',$r['allocated']['operatorFees']['totalZat'],'only known fee source counted');
    eq('109200000',$r['allocated']['sharedMining']['totalZat'],'independent shared allocation remains known');
}
foreach (["UPDATE blocks SET amount='12.50000001' WHERE id=1","UPDATE zcl_reward_rounds SET credited_sat='1' WHERE block_id=1",
    "UPDATE zcl_reward_rounds SET blockhash='wrong' WHERE block_id=1","UPDATE blocks SET category='new' WHERE id=1"] as $change) {
    $db=database();$db->exec($change);$r=report($db);
    eq('partial',$r['status'],'inconsistent source partial');
    eq('800000',$r['allocated']['operatorFees']['totalZat'],'bad source cannot create fees');
    eq('10000000',$r['allocated']['sharedMining']['totalZat'],'bad source cannot create shared allocations');
}
$db=database();$db->exec("UPDATE blocks SET category='orphan',confirmations=-1 WHERE id=1");$r=report($db);
eq('partial',$r['status'],'post-credit reorg remains disputed even before hold writer runs');
eq('800000',$r['allocated']['operatorFees']['totalZat'],'orphan fee excluded');
eq(2,$r['excludedOrphanRounds'],'orphan count');
$db=database();$db->exec('UPDATE blocks SET confirmations=100 WHERE id=1');$r=report($db);
eq('0',$r['allocated']['operatorFees']['matureZat'],'100 confirmations is not mature');
eq('partial',$r['status'],'credited shared amount cannot become immature silently');
$db=database();$db->exec("INSERT INTO earnings VALUES(1,1,2,'0.10000000',0)");$r=report($db);
eq('99200000',$r['allocated']['sharedMining']['totalZat'],'duplicate shared allocation omitted');
eq('partial',$r['status'],'duplicate shared allocation flagged');
$db=database();$db->exec('DELETE FROM blocks WHERE id=1');eq('partial',report($db)['status'],'missing source block reported');
$db=database();$db->exec("UPDATE zcl_reward_rounds SET fee_sat=NULL WHERE block_id=1; UPDATE earnings SET amount='99' WHERE blockid=1 AND userid=1");
eq(1,report($db)['unknownRounds'],'one round with several errors counts once');
$db=database();$db->exec('DROP TABLE zcl_reward_rounds');$r=report($db);
eq('unavailable',$r['status'],'missing schema unavailable');eq(null,$r['received'],'unavailable never fabricated zero');
foreach (["UPDATE zcl_payment_batches SET state='held' WHERE id='owner'","DELETE FROM zcl_payment_operations WHERE batch_id='owner' AND kind='send'",
    "UPDATE zcl_payment_operations SET txid='wrong' WHERE batch_id='owner' AND kind='send'",
    "INSERT INTO zcl_payment_operations VALUES('owner','send','confirmed','".str_repeat('f',64)."')"] as $change) {
    $db=database();$db->exec($change);$r=report($db);
    eq('partial',$r['status'],'uncertain operator transfer partial');eq('5000000',$r['received']['totalZat'],'only known shared transfer remains');
}
foreach (["UPDATE payouts SET completed=0 WHERE id=1","UPDATE payouts SET tx='wrong' WHERE id=1",
    "UPDATE payouts SET amount='0.05000001' WHERE id=1","DELETE FROM payouts WHERE id=1","DELETE FROM zcl_payment_items WHERE payout_id=1",
    "DELETE FROM zcl_payment_batches WHERE id='miners'","UPDATE zcl_payment_items SET address='another-address' WHERE payout_id=1"] as $change) {
    $db=database();$db->exec($change);$r=report($db);
    eq('partial',$r['status'],'uncertain shared transfer partial');eq('8970000',$r['received']['totalZat'],'only known operator transfer remains');
}
$db=database();$db->exec("UPDATE zcl_payment_operations SET txid='".str_repeat('d',64)."' WHERE batch_id='owner' AND kind='send'");$r=report($db);
eq('0',$r['received']['totalZat'],'same transaction cannot count under two purposes');
eq(2,$r['unknownPayments'],'duplicate transaction evidence disputed');
$db=database();$db->exec("UPDATE zcl_payment_batches SET state='operation' WHERE id='pending'");eq('ok',report($db)['status'],'known pending operation is not a receipt or an unknown');
$db=database();$db->exec("UPDATE zcl_payment_batches SET state='held' WHERE id='pending'");eq('partial',report($db)['status'],'held pending outcome explicit');
$db=database();$q=$db->prepare('INSERT INTO accounts VALUES(?,?,?)');$q->execute([4,1,ZclTreasuryStats::RECIPIENT]);
eq('unavailable',report($db)['status'],'ambiguous donation account fails closed');
$db=database(false);$q=$db->prepare('INSERT INTO blocks VALUES(?,1,?,\'new\',0,\'1\')');
for($i=1;$i<=10001;++$i)$q->execute([$i,str_pad(dechex($i),64,'0',STR_PAD_LEFT)]);
eq('unavailable',report($db)['status'],'bounded full history never silently truncates');
echo "Treasury statistics: {$checks} checks passed.\n";
