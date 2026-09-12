<?php
require __DIR__ . '/../../yiimp2/services/ZclAmount.php';
require __DIR__ . '/../../yiimp2/services/ZclPayoutLedger.php';
require __DIR__ . '/../../yiimp2/services/ZclNodeReadiness.php';
require __DIR__ . '/../../yiimp2/services/ZclPayoutCoordinator.php';
use app\services\ZclAmount;
use app\services\ZclPayoutLedger;
use app\services\ZclPayoutCoordinator;

$dsn = getenv('ZCL_TEST_DSN') ?: 'sqlite::memory:';
if (!str_starts_with($dsn, 'sqlite:') && !preg_match('/^mysql:host=zcl-ledger-db;dbname=zcl_payout_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('This suite may only use its disposable zcl_payout_test database');
}
function connect(): PDO {
    global $dsn;
    return new PDO($dsn, getenv('ZCL_TEST_DB_USER') ?: 'root', getenv('ZCL_TEST_DB_PASSWORD') ?: 'test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function setup(): PDO {
    $db = connect();
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    foreach (['zcl_operator_credits','zcl_reward_rounds','zcl_payment_items','zcl_payment_operations','zcl_payment_batches','zcl_payment_control','payouts','earnings','accounts','blocks','coins','zcl_accounting_holds'] as $table) $db->exec("DROP TABLE IF EXISTS $table");
    $id = $mysql ? 'INT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $engine = $mysql ? ' ENGINE=InnoDB' : '';
    $schema = [
        "CREATE TABLE coins (id INT PRIMARY KEY,symbol VARCHAR(16),enable INT)$engine",
        "CREATE TABLE accounts (id $id, coinid INT,username VARCHAR(128),balance DECIMAL(24,8),is_locked INT DEFAULT 0,payout_threshold DECIMAL(24,8))$engine",
        "CREATE TABLE payouts (id $id,account_id INT,idcoin INT,time INT,completed INT,amount DECIMAL(24,8),fee DECIMAL(24,8),tx VARCHAR(128),errmsg TEXT)$engine",
        "CREATE TABLE blocks (id INT PRIMARY KEY,category VARCHAR(32),confirmations INT,coin_id INT DEFAULT 1,blockhash VARCHAR(64) DEFAULT '')$engine",
        "CREATE TABLE earnings (id INT PRIMARY KEY,userid INT,coinid INT,blockid INT,amount DECIMAL(24,8),status INT,price DECIMAL(24,8),mature_time INT)$engine",
        "CREATE TABLE zcl_reward_rounds (block_id INT PRIMARY KEY,coin_id INT,blockhash VARCHAR(64),reward_sat DECIMAL(24,0),credited_sat DECIMAL(24,0),retained_sat DECIMAL(24,0))$engine",
        "CREATE TABLE zcl_accounting_holds (coin_id INT PRIMARY KEY,reason VARCHAR(255),created_at INT)$engine",
    ];
    foreach ($schema as $sql) $db->exec($sql);
    $migration = file_get_contents(__DIR__ . '/../../sql/2026-09-12-zcl-payout-ledger.sql') . file_get_contents(__DIR__ . '/../../sql/2026-09-13-zcl-operator-fees.sql');
    if (!$mysql) {
        $migration = preg_replace('/ALTER TABLE[^;]+MODIFY[^;]+;/', '', $migration);
        $migration = str_replace(' ENGINE=InnoDB', '', $migration);
        $migration = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $migration);
        $migration = preg_replace('/^    KEY [^\n]+\n/m', '', $migration);
        $migration = preg_replace('/UNIQUE KEY [a-z_]+ \(/', 'UNIQUE (', $migration);
        $migration = str_replace(",\n)", "\n)", $migration);
    }
    $db->exec($migration);
    $db->exec("INSERT INTO coins VALUES (1,'ZCL',1)");
    $db->exec("INSERT INTO accounts (id,coinid,username,balance) VALUES (1,1,'tRecipient11111111111111111',0.0992),(2,1,'tRecipient22222222222222222',0.0992)");
    return $db;
}
function check($ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function scalar(PDO $db, string $sql) { return $db->query($sql)->fetchColumn(); }
function balance(PDO $db, int $id): int { return ZclAmount::parse(scalar($db,"SELECT balance FROM accounts WHERE id=$id")); }
function config(): array { return ['pool_taddress'=>'tPool111111111111111111111','pool_zaddress'=>'zsPool111111111111111111111111']; }
