<?php
namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;
use app\models\Coins;
use app\services\ZclPayoutService;

/** Dedicated ZCL payout worker. Run tick once a minute; never run a second sender. */
class ZclPayoutController extends Controller
{
    public function actionTick(): int
    {
        if (!ZclPayoutService::enabled()) {
            $this->stdout("ZCL payouts disabled.\n");
            return ExitCode::OK;
        }
        $coin = Coins::find()->where(['symbol' => 'ZCL'])->one();
        if (!$coin) throw new \RuntimeException('ZCL coin record missing');
        $this->stdout((new ZclPayoutService())->tick($coin) . "\n");
        return ExitCode::OK;
    }

    /** Read-only journal status; no wallet mutation or recovery-by-retry action. */
    public function actionStatus(): int
    {
        $coin = Coins::find()->where(['symbol' => 'ZCL'])->one();
        if (!$coin) throw new \RuntimeException('ZCL coin record missing');
        $ledger = ZclPayoutService::ledger($coin);
        $batch = $ledger->active();
        $this->stdout(json_encode([
            'enabled' => ZclPayoutService::enabled(),
            'accounting_hold' => $ledger->accountingHeld(),
            'batch_id' => $batch['id'] ?? null,
            'state' => $batch['state'] ?? 'idle',
            'recipients' => count($batch['items'] ?? []),
            'amount_zat' => isset($batch['amount_zat']) ? (string) $batch['amount_zat'] : '0',
            'operation_id' => $batch['operation']['opid'] ?? null,
            'txid' => $batch['operation']['txid'] ?? null,
            'error' => $batch['error'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        return ExitCode::OK;
    }
}
