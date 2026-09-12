<?php
// No wallet, daemon or miner calls. Integration mode requires a disposable DB.
require __DIR__ . '/../../yiimp2/services/ZclRewardLedger.php';
use app\services\ZclRewardLedger as Ledger;

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable $expected) { return; }
    throw new RuntimeException($message);
}
check(Ledger::satoshis('1.23456789') === '123456789', 'Exact satoshi conversion');
check(Ledger::coins('1') === '0.00000001', 'Exact coin conversion');
foreach (['-1', 'NaN', '1e2', '0.000000001', '1.000000001'] as $bad) {
    rejects(fn() => Ledger::satoshis($bad), 'Reject noncanonical money');
}
check(Ledger::split('1', [3 => '1', 1 => '1', 2 => '1']) === [1 => '1', 2 => '0', 3 => '0'], 'Stable remainder tie');
check(Ledger::split('10', [1 => '1', 2 => '2']) === [1 => '3', 2 => '7'], 'Largest remainder');
for ($i = 1; $i <= 200; ++$i) {
    $reward = (string) ($i * 7919);
    $weights = [];
    for ($id = 1; $id <= 19; ++$id) $weights[$id] = (string) ($i * $id * $id + 1);
    $split = Ledger::split($reward, $weights);
    check(array_sum($split) === (int) $reward, 'Every round conserves all satoshis');
    check($split === Ledger::split($reward, array_reverse($weights, true)), 'Query order does not change allocation');
}
echo "PASS exact reward arithmetic\n";
if (($argv[1] ?? '--unit') === '--unit') exit(0);

function connect(): PDO {
    $dsn = getenv('ZCL_ACCOUNTING_TEST_DSN');
    if (!$dsn || getenv('ZCL_ACCOUNTING_TEST_DESTRUCTIVE') !== 'yes') {
        throw new RuntimeException('Set the disposable accounting test database environment explicitly');
    }
    $db = new PDO($dsn, getenv('ZCL_ACCOUNTING_TEST_USER'), getenv('ZCL_ACCOUNTING_TEST_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (!preg_match('/^zcl_accounting_test_[a-z0-9_]+$/D', (string) $db->query('SELECT DATABASE()')->fetchColumn())) {
        throw new RuntimeException('Refusing fixtures outside a zcl_accounting_test_* database');
    }
    return $db;
}
$db = connect();
if (($argv[1] ?? '') === '--allocate') {
    (new Ledger($db))->allocate(1, 1, false, '0');
    exit(0);
}
foreach (['zcl_operator_credits', 'zcl_reward_rounds', 'zcl_accounting_holds', 'earnings', 'shares', 'blocks', 'accounts', 'coins'] as $table) {
    $db->exec("DROP TABLE IF EXISTS {$table}");
}
$db->exec("CREATE TABLE coins (id INT PRIMARY KEY, symbol VARCHAR(16), algo VARCHAR(16), auto_exchange TINYINT) ENGINE=InnoDB;
CREATE TABLE blocks (id INT UNSIGNED PRIMARY KEY, coin_id INT, algo VARCHAR(16), blockhash CHAR(64), height INT,
 category VARCHAR(16), amount DOUBLE, time INT, userid INT, solo TINYINT, effort DOUBLE, difficulty DOUBLE, confirmations INT) ENGINE=InnoDB;
CREATE TABLE accounts (id INT PRIMARY KEY, coinid INT, no_fees TINYINT, donation INT, last_earning INT) ENGINE=InnoDB;
CREATE TABLE shares (id BIGINT PRIMARY KEY, userid INT, coinid INT, algo VARCHAR(16), blocknumber INT,
 time INT, blockrewarded INT, valid TINYINT, solo TINYINT, difficulty DOUBLE, pid INT) ENGINE=InnoDB;
CREATE TABLE earnings (id INT AUTO_INCREMENT PRIMARY KEY, userid INT, coinid INT, blockid INT, create_time INT,
 amount DOUBLE, price DOUBLE, status INT, mature_time INT, UNIQUE KEY user_block(userid,blockid)) ENGINE=InnoDB;");
$db->exec(file_get_contents(__DIR__ . '/../../sql/2026-09-11-zcl-reward-ledger.sql'));
$operatorMigration = file_get_contents(__DIR__ . '/../../sql/2026-09-13-zcl-operator-fees.sql');
preg_match('/ALTER TABLE zcl_reward_rounds[^;]+;/', $operatorMigration, $feeSchema);
preg_match('/CREATE TABLE zcl_operator_credits[^;]+;/', $operatorMigration, $creditSchema);
$db->exec($feeSchema[0]);
$db->exec($creditSchema[0]);

function fixture(PDO $db): void {
    foreach (['zcl_operator_credits', 'zcl_reward_rounds', 'zcl_accounting_holds', 'earnings', 'shares', 'blocks', 'accounts', 'coins'] as $table) {
        $db->exec("DELETE FROM {$table}");
    }
    $db->exec("INSERT INTO coins VALUES (1,'ZCL','equihash192',0),(2,'OTHER','equihash192',0);
      INSERT INTO blocks VALUES (1,1,'equihash192',REPEAT('a',64),100,'immature',1,1000,1,0,0,10,1);
      INSERT INTO accounts VALUES (1,1,0,0,0),(2,1,0,0,0),(3,1,0,0,0);
      INSERT INTO shares VALUES
      (1,1,1,'equihash192',100,999,NULL,1,0,1,1),
      (2,2,1,'equihash192',100,999,NULL,1,0,1,1),
      (3,3,1,'equihash192',100,999,NULL,1,0,1,1),
      (4,1,1,'equihash192',100,999,NULL,1,1,100,1),
      (5,1,1,'equihash192',100,999,NULL,0,0,100,1),
      (6,1,1,'equihash192',101,1001,NULL,1,0,100,1),
      (7,1,2,'equihash192',100,999,NULL,1,0,100,1);");
}
function scalar(PDO $db, string $sql) { return $db->query($sql)->fetchColumn(); }
fixture($db);
$ledger = new Ledger($db);
check($ledger->allocate(1, 1, false, '0'), 'First round commits');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '1.00000000', 'Zero fee pays exact full reward');
check(scalar($db, 'SELECT amount FROM earnings WHERE userid=1') === '0.33333334', 'Remainder assigned deterministically');
check((int) scalar($db, 'SELECT COUNT(*) FROM shares WHERE blockrewarded=100') === 3, 'Consume only selected valid shared shares');
check(!$ledger->allocate(1, 1, false, '0'), 'Replay is idempotent');
check((int) scalar($db, 'SELECT COUNT(*) FROM earnings') === 3, 'Replay creates no earnings');
$db->exec("INSERT INTO blocks SELECT 2,coin_id,algo,blockhash,height,category,amount,time,userid,solo,effort,difficulty,confirmations FROM blocks WHERE id=1");
check(!$ledger->allocate(1, 2, false, '0'), 'Same block hash with another database id cannot allocate twice');
echo "PASS round conservation, share isolation and replay\n";

fixture($db);
$db->exec("CREATE TRIGGER fail_second_earning BEFORE INSERT ON earnings FOR EACH ROW
 BEGIN IF NEW.userid=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected insert failure'; END IF; END");
rejects(fn() => $ledger->allocate(1, 1, false, '0'), 'Insert failure must propagate');
check((int) scalar($db, 'SELECT COUNT(*) FROM earnings') === 0, 'All earnings rolled back');
check((int) scalar($db, 'SELECT SUM(last_earning) FROM accounts') === 0, 'Account mutations rolled back');
check((int) scalar($db, 'SELECT COUNT(*) FROM shares WHERE blockrewarded IS NOT NULL') === 0, 'Share marking rolled back');
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_reward_rounds') === 0, 'No partial journal');
$db->exec('DROP TRIGGER fail_second_earning');
check($ledger->allocate(1, 1, false, '0'), 'Retry after failure commits once');
echo "PASS injected database failure rollback and retry\n";

fixture($db);
$db->exec('UPDATE accounts SET coinid=2 WHERE id=2');
rejects(fn() => $ledger->allocate(1, 1, false, '0'), 'Mismatched payout coin must stop allocation');
check((int) scalar($db, 'SELECT COUNT(*) FROM earnings') === 0, 'Missing account cannot silently burn share');
fixture($db);
$db->exec('UPDATE accounts SET donation=10 WHERE id=1');
$ledger->allocate(1, 1, false, '1');
$round = $db->query('SELECT * FROM zcl_reward_rounds')->fetch(PDO::FETCH_ASSOC);
check(bcadd($round['credited_sat'], $round['retained_sat'], 0) === $round['reward_sat'], 'Fees and donations remain fully attributed');
check($round['fee_sat'] === '1000000' && bccomp($round['retained_sat'], $round['fee_sat']) > 0, 'Exact operator fee excludes miner donations');
echo "PASS native coin enforcement and fee conservation\n";

fixture($db);
$ledger->allocate(1, 1, false, '1');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '0.99000000', 'Launch fee credits exact 99 percent');
check(scalar($db, 'SELECT retained_sat FROM zcl_reward_rounds') === '1000000', 'Launch fee is floor(reward satoshis / 100)');
fixture($db);
$db->exec('UPDATE blocks SET amount=0.00000101');
$ledger->allocate(1, 1, false, '1');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '0.00000100', 'Fee rounding remainder stays with miners');
check(scalar($db, 'SELECT retained_sat FROM zcl_reward_rounds') === '1', 'Round fee is exactly one satoshi');
echo "PASS exact 1 percent fee\n";

fixture($db);
$ledger->allocate(1, 1, false, '0.8');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '0.99200000', 'Launch fee credits exact 99.2 percent');
check(scalar($db, 'SELECT retained_sat FROM zcl_reward_rounds') === '800000', 'Launch fee is floor(reward satoshis * 80 / 10000)');
fixture($db);
$db->exec('UPDATE blocks SET amount=0.00000126');
$ledger->allocate(1, 1, false, '0.8');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '0.00000125', '0.8 percent rounding stays with miners');
check(scalar($db, 'SELECT retained_sat FROM zcl_reward_rounds') === '1', 'Round fee is exactly one satoshi');
echo "PASS exact 0.8 percent launch fee\n";

fixture($db);
$ledger->allocate(1, 1, false, '0');
$ledger->transition(1, 1, 'generate', 101);
$db->exec('UPDATE earnings SET status=2 WHERE userid=1');
$ledger->transition(1, 1, 'generate', 102);
check((int) scalar($db, 'SELECT status FROM earnings WHERE userid=1') === 2, 'Maturity never resets credited status');
$ledger->transition(1, 1, 'orphan', -1);
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_accounting_holds') === 1, 'Post-credit reorg holds accounting');
check((int) scalar($db, 'SELECT COUNT(*) FROM earnings') === 3, 'Reorg retains allocation evidence');
check((int) scalar($db, 'SELECT COUNT(*) FROM earnings WHERE status=-1') === 2, 'Only pending earnings invalidated');
rejects(fn() => $ledger->allocate(1, 1, false, '0'), 'Hold must prevent new allocation');
$ledger->transition(1, 1, 'generate', 103);
check((int) scalar($db, 'SELECT status FROM earnings WHERE userid=1') === 2, 'Reinstatement does not duplicate credits');
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_accounting_holds') === 1, 'Hold needs explicit reconciliation');
echo "PASS maturity, reorg hold and evidence preservation\n";

fixture($db);
$ledger->allocate(1, 1, false, '0.8');
$ledger->transition(1, 1, 'generate', 101);
$db->exec('INSERT INTO zcl_operator_credits(block_id,coin_id,amount_zat,created_at) VALUES(1,1,800000,1)');
$ledger->transition(1, 1, 'generate', 100);
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_accounting_holds') === 1, 'Operator-credit reorg holds before miner credits');
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_operator_credits') === 1, 'Operator credit evidence retained');
echo "PASS operator fee source reorg holds before miner balances are cleared\n";


fixture($db);
$db->beginTransaction();
$db->query('SELECT id FROM coins WHERE id=1 FOR UPDATE')->fetchColumn();
$processes = [];
for ($i = 0; $i < 2; ++$i) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--allocate'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    check(is_resource($process), 'Create competing accounting worker');
    fclose($pipes[0]);
    $processes[] = [$process, $pipes];
}
usleep(200000);
$db->commit();
foreach ($processes as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'Concurrent worker failed: ' . $errors);
}
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_reward_rounds') === 1, 'Concurrent workers commit one round');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '1.00000000', 'Concurrent workers do not overcredit');
echo "PASS real InnoDB concurrent allocation\n";

fixture($db);
$db->exec("INSERT INTO blocks SELECT 2,coin_id,algo,REPEAT('b',64),101,category,amount,1001,userid,solo,effort,difficulty,confirmations FROM blocks WHERE id=1");
check(!$ledger->allocate(1, 2, false, '1'), 'Later ZCL round must wait for earlier pending round');
check((int) scalar($db, 'SELECT COUNT(*) FROM earnings') === 0, 'Out-of-order worker cannot steal earlier shares');
check($ledger->allocate(1, 1, false, '1'), 'Earlier round commits');
check($ledger->allocate(1, 2, false, '1'), 'Later round then commits');
check((int) scalar($db, 'SELECT COUNT(*) FROM zcl_reward_rounds') === 2, 'Separate windows have separate journals');
check(scalar($db, 'SELECT SUM(amount) FROM earnings') === '1.98000000', 'Two rounds each retain exactly one percent');
echo "PASS ZCL round ordering\n";
