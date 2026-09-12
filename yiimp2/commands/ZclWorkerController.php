<?php
namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use app\models\Coins;
use app\services\{BlockService,PaymentService,ZclNodeReadiness,ZclWalletRPC,ZclPayoutService};

/** ZCL-only schedules; never seeds exchange, rental, purchase or generic payout jobs. */
final class ZclWorkerController extends Controller
{
    private function readyCoin(): ?Coins
    {
        if (!defined('YIIMP_ZCL_WORKER_ENABLED') || YIIMP_ZCL_WORKER_ENABLED !== true) return null;
        $coin=Coins::find()->where(['symbol'=>'ZCL','enable'=>1])->one();
        if (!$coin) return null;
        $rpc=new ZclWalletRPC((string)$coin->rpchost,(int)$coin->rpcport,(string)$coin->rpcuser,(string)$coin->rpcpasswd);
        $chain=$rpc('getblockchaininfo',[]);
        if (!is_string($chain['bestblockhash'] ?? null)) return null;
        $header=$rpc('getblockheader',[$chain['bestblockhash']]);
        $peers=$rpc('getconnectioncount',[]);
        return ZclNodeReadiness::ready($chain,$header,(int)$peers,time()) ? $coin : null;
    }

    /** Run every 15 seconds. Preserve all share history while validation is ongoing. */
    public function actionTick(): int
    {
        $coin=$this->readyCoin();
        if (!$coin) {$this->stdout("Worker gated: disabled, syncing, held, disconnected or stale.\n");return ExitCode::OK;}
        if (!Yii::$app->mutex->acquire('zcl-mainnet-worker',0)) return ExitCode::OK;
        try {
            $service=new BlockService();
            $service->processNewBlocks((int)$coin->id);
            $service->updateBlockConfirmations((int)$coin->id);
            (new PaymentService())->clearEarnings((int)$coin->id);
            $last=(int)Yii::$app->cache->get('zcl-mainnet-scan-at');
            if ($last < time()-60) {
                $service->scanTransactions((int)$coin->id);
                $service->updatePoolBalances((int)$coin->id);
                Yii::$app->cache->set('zcl-mainnet-scan-at',time());
            }
            $this->stdout("ZCL block and earnings pipeline checked.\n");
        } finally {Yii::$app->mutex->release('zcl-mainnet-worker');}
        return ExitCode::OK;
    }

    /** Run once a minute. The dedicated coordinator retains its own durable locks. */
    public function actionPayout(): int
    {
        $coin=$this->readyCoin();
        if (!$coin || !ZclPayoutService::enabled()) {$this->stdout("Payout worker gated.\n");return ExitCode::OK;}
        $this->stdout((new ZclPayoutService())->tick($coin)."\n");
        return ExitCode::OK;
    }

    /** Sanitized readiness flags for the static pool status publisher. */
    public function actionStatus(): int
    {
        $ready=$this->readyCoin() !== null;
        $this->stdout(json_encode([
            'ready'=>$ready,
            'payoutsEnabled'=>ZclPayoutService::enabled(),
            'operatorPaymentsEnabled'=>defined('YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED') && YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED === true,
            'workerEnabled'=>defined('YIIMP_ZCL_WORKER_ENABLED') && YIIMP_ZCL_WORKER_ENABLED === true,
        ],JSON_THROW_ON_ERROR)."\n");
        return ExitCode::OK;
    }
}
