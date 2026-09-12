<?php
namespace app\services;

use Yii;
use app\models\Coins;

/** Yii adapter for the dedicated ZCL worker; the generic sender remains blocked. */
final class ZclPayoutService
{
    public static function enabled(): bool
    {
        return PaymentService::paymentsEnabled()
            && defined('YIIMP_ZCL_PAYOUTS_ENABLED') && YIIMP_ZCL_PAYOUTS_ENABLED === true;
    }

    public static function ledger(Coins $coin): ZclPayoutLedger
    {
        if (strtoupper((string) $coin->symbol) !== 'ZCL') throw new \RuntimeException('ZCL-only payout ledger');
        Yii::$app->db->open();
        return new ZclPayoutLedger(Yii::$app->db->pdo, (int) $coin->id);
    }

    public function tick(Coins $coin): string
    {
        if (!self::enabled()) return 'disabled';
        if (!$coin->enable) return 'coin-disabled';
        foreach (['YIIMP_ZCL_POOL_TADDRESS', 'YIIMP_ZCL_POOL_ZADDRESS'] as $name) {
            if (!defined($name)) throw new \RuntimeException('Missing configuration: ' . $name);
        }
        $config = [
            'pool_taddress' => YIIMP_ZCL_POOL_TADDRESS,
            'pool_zaddress' => YIIMP_ZCL_POOL_ZADDRESS,
            'minimum_zat' => ZclAmount::parse(defined('YIIMP_ZCL_PAYOUT_MIN') ? YIIMP_ZCL_PAYOUT_MIN : '0.05'),
        ];
        if (defined('YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED') && YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED === true) {
            if (!defined('YIIMP_ZCL_OPERATOR_TADDRESS')) throw new \RuntimeException('Missing private operator recipient');
            $config += [
                'operator_enabled' => true,
                'operator_taddress' => YIIMP_ZCL_OPERATOR_TADDRESS,
                'operator_reserve_zat' => ZclAmount::parse(defined('YIIMP_ZCL_OPERATOR_RESERVE') ? YIIMP_ZCL_OPERATOR_RESERVE : '0.01'),
            ];
        }
        $rpc = new ZclWalletRPC((string) $coin->rpchost, (int) $coin->rpcport, (string) $coin->rpcuser, (string) $coin->rpcpasswd);
        return (new ZclPayoutCoordinator(self::ledger($coin), $rpc, $config, true))->tick();
    }
}
