<?php
// Run each mode in a fresh PHP process; no Yii, database, wallet or network needed.
namespace app\models {
    class Coins {
        public $symbol = 'ZCL';
        public $id = 1;
        public static function find() { throw new \RuntimeException('Unexpected coin query'); }
    }
    class Payouts {
        public static function find() {
            return new class {
                public function where($x) { return $this; }
                public function andWhere($x) { return $this; }
                public function exists() { return true; }
            };
        }
    }
    class Accounts {
        public static function findOne($x) { throw new \RuntimeException('Unexpected account mutation'); }
    }
}
namespace app\components\rpc {
    class WalletRPC {
        public function __construct($coin) { throw new \RuntimeException('Unexpected wallet access'); }
    }
}
namespace app\jobs {
    abstract class BaseJob {}
}
namespace yii\console {
    class Controller {
        public function stderr($text) { return strlen($text); }
    }
    class ExitCode { const UNSPECIFIED_ERROR = 1; }
}
namespace {
    class Yii {
        public static $app;
        public static function warning($message, $category) {}
        public static function error($message, $category) {}
    }
    function debuglog($message) {}
    function dboscalar($sql, $params) { return 1; }
    function getdbo($type, $id) { throw new RuntimeException('Unexpected legacy account query'); }
    function getdbolist($type, $where) { throw new RuntimeException('Unexpected legacy payout query'); }
    function dborun($sql) { throw new RuntimeException('Unexpected legacy balance mutation'); }
    class WalletRPC {
        public function __construct($coin) { throw new RuntimeException('Unexpected legacy wallet access'); }
    }
    $mode = $argv[1] ?? 'unset';
    if ($mode !== 'unset') {
        $values = ['disabled' => false, 'enabled' => true, 'string' => 'true', 'integer' => 1];
        define('YIIMP_PAYMENTS_ENABLED', $values[$mode]);
    }
    define('YIIMP_PRODUCTION', true);
    require __DIR__ . '/../../yiimp2/services/PaymentService.php';
    require __DIR__ . '/../../yiimp2/jobs/earnings/PaymentsJob.php';
    require __DIR__ . '/../../yiimp2/commands/PayoutController.php';
    require __DIR__ . '/../../web/yaamp/core/backend/payment.php';
    function check($condition, $message) {
        if (!$condition) throw new RuntimeException($message);
    }
    $service = new \app\services\PaymentService();
    $coin = new \app\models\Coins();
    check($service::paymentsEnabled() === ($mode === 'enabled'), 'Strict opt-in required');
    if ($mode !== 'enabled') {
        $service->doPayments();
        BackendPayments();
        $job = new class extends \app\jobs\earnings\PaymentsJob {
            public function test() { $this->perform(); }
        };
        $job->test();
        $command = new \app\commands\PayoutController();
        check($command->actionRedotx(str_repeat('a', 64)) === 1, 'Replay must be blocked');
    }
    foreach (['ZCL', 'zcl'] as $symbol) {
        $coin->symbol = $symbol;
        $service->payCoin($coin);
        BackendCoinPayments($coin);
    }
    // Even explicit opt-in never permits a new broadcast while an old one is unresolved.
    $coin->symbol = 'BTC';
    $service->payCoin($coin);
    BackendCoinPayments($coin);
    check($service->cancelFailedPayment(1) === 0.0, 'Unknown payouts must not be refunded');
    check(BackendUserCancelFailedPayment(1) === 0.0, 'Legacy unknown payouts must not be refunded');
    echo "PASS payout gates: {$mode}\n";
}
