<?php
namespace app\services;

use RuntimeException;
use Throwable;

/**
 * One durable step per tick: reserve -> shield -> confirm -> send -> confirm.
 * Mutation RPCs are never retried. Missing opids, daemon restarts and uncertain
 * outcomes hold the batch with its reservation intact for operator reconciliation.
 */
final class ZclPayoutCoordinator
{
    private ZclPayoutLedger $ledger;
    private $rpc;
    private array $config;
    private bool $enabled;

    public function __construct(ZclPayoutLedger $ledger, callable $rpc, array $config, $enabled = false)
    {
        $this->ledger = $ledger;
        $this->rpc = $rpc;
        $this->enabled = $enabled === true;
        $this->config = $config + [
            'network' => 'main', 'minimum_zat' => 5000000, 'fee_zat' => 10000,
            'confirmations' => 6, 'coinbase_confirmations' => 101,
            'max_recipients' => 50, 'shield_limit' => 50,
            'operator_enabled' => false, 'operator_taddress' => null,
            'operator_reserve_zat' => 1000000, 'operator_minimum_zat' => 100000,
        ];
        foreach (['pool_taddress', 'pool_zaddress'] as $key) {
            if (!is_string($this->config[$key] ?? null) || strlen($this->config[$key]) < 20) throw new RuntimeException('Pool wallet addresses must be configured');
        }
        foreach (['minimum_zat','fee_zat','confirmations','coinbase_confirmations','max_recipients','shield_limit'] as $key) {
            if (!is_int($this->config[$key])) throw new RuntimeException('Payout limits require exact integer values');
        }
        if ($this->config['operator_enabled'] !== false && $this->config['operator_enabled'] !== true) throw new RuntimeException('Operator remittance requires an explicit boolean');
        if ($this->config['operator_enabled'] && (!is_string($this->config['operator_taddress']) || strlen($this->config['operator_taddress']) < 20
            || $this->config['operator_taddress'] === $this->config['pool_taddress']
            || !is_int($this->config['operator_reserve_zat']) || $this->config['operator_reserve_zat'] < 1000000 || $this->config['operator_reserve_zat'] > ZclAmount::MAX
            || !is_int($this->config['operator_minimum_zat']) || $this->config['operator_minimum_zat'] < 100000 || $this->config['operator_minimum_zat'] > ZclAmount::MAX)) {
            throw new RuntimeException('Unsafe operator fee configuration');
        }
        if (!in_array($this->config['network'], ['main', 'test', 'regtest'], true)
            || $this->config['confirmations'] < 6 || $this->config['coinbase_confirmations'] < 101
            || $this->config['max_recipients'] < 1 || $this->config['max_recipients'] > 50
            || $this->config['shield_limit'] < 1 || $this->config['shield_limit'] > 50
            || $this->config['minimum_zat'] < 1 || $this->config['fee_zat'] < 1 || $this->config['fee_zat'] > 100000) {
            throw new RuntimeException('Unsafe payout configuration');
        }
    }

    private function call(string $method, array $params = [])
    {
        $result = ($this->rpc)($method, $params);
        if ($result === false || $result === null) throw new RuntimeException('Wallet RPC failed: ' . $method);
        return $result;
    }

    /** Disabled means no database or RPC work at all. */
    public function tick(): string
    {
        if (!$this->enabled) return 'disabled';
        if (!$this->ledger->claim()) return 'busy';
        try {
            $batch = $this->ledger->active();
            if ($batch && $batch['state'] === 'held') return 'held';
            if ($batch && $batch['config'] !== $this->config) {
                $this->ledger->hold($batch, 'Configuration changed while a payout batch was active');
                return 'held';
            }
            // Detect a process death before touching the wallet. We cannot know
            // whether its last request was accepted before the connection failed.
            if ($batch && ($batch['operation']['state'] ?? '') === 'submitting') {
                $this->ledger->hold($batch, 'Unknown RPC submission outcome; reconcile wallet operations, never retry');
                return 'held';
            }
            $chain = $this->call('getblockchaininfo');
            if (($chain['chain'] ?? '') !== $this->config['network']) throw new RuntimeException('Unexpected wallet network');
            $mainnet = $this->config['network'] === 'main';
            $bootstrapReady = $mainnet ? ($chain['bootstrap_validation']['tip_hold'] ?? true) === false
                : !($chain['bootstrap_validation']['tip_hold'] ?? false);
            // Mainnet shares the worker's strict live/cached contract. Keep
            // legacy test-network daemons without hold diagnostics compatible.
            $finalizationReady = $mainnet || array_key_exists('live_corroboration', $chain)
                ? ZclNodeReadiness::finalizationReady($chain)
                : !($chain['finalization_hold']['held'] ?? false);
            if (($chain['initialblockdownload'] ?? false) || ($chain['verificationprogress'] ?? 0) < 0.9999
                || !isset($chain['blocks'], $chain['headers']) || $chain['blocks'] !== $chain['headers']
                || !$bootstrapReady || !$finalizationReady) return 'syncing';
            if ($this->config['operator_enabled'] && !$this->ledger->auditOperatorCredits()) return 'accounting-held';
            if (!$batch) {
                foreach ($this->ledger->recentConfirmedSends() as $txid) {
                    $tx = $this->call('gettransaction', [$txid]);
                    if (($tx['txid'] ?? '') !== $txid || (int) ($tx['confirmations'] ?? 0) < $this->config['confirmations'] || !empty($tx['walletconflicts'])) {
                        $this->ledger->holdAccounting('Previously completed ZCL payout lost confirmations: ' . $txid);
                        return 'accounting-held';
                    }
                }
                $batch = $this->ledger->reserve($this->config);
                if (!$batch && $this->config['operator_enabled']) {
                    $this->ledger->creditMatureOperatorFees();
                    // Only an already-confirmed shielded surplus can fund an
                    // operator transfer. Miner batches retain priority.
                    $available = ZclAmount::parse($this->call('z_getbalance', [$this->config['pool_zaddress'], $this->config['confirmations']]));
                    $batch = $this->ledger->reserveOperator($this->config, $available);
                }
                if (!$batch) return 'idle';
            }
            switch ($batch['state']) {
                case 'funding': return $this->fund($batch);
                case 'operation': return $this->watch($batch);
                case 'confirming': return $this->confirm($batch);
                default: throw new RuntimeException('Unknown payout batch state');
            }
        } finally {
            $this->ledger->release();
        }
    }

    private function fund(array $batch): string
    {
        if ($this->ledger->accountingHeld()) return 'accounting-held';
        $cfg = $this->config;
        $transparent = $this->call('validateaddress', [$cfg['pool_taddress']]);
        $shielded = $this->call('z_validateaddress', [$cfg['pool_zaddress']]);
        if (($transparent['isvalid'] ?? false) !== true || ($transparent['ismine'] ?? false) !== true
            || ($shielded['isvalid'] ?? false) !== true || ($shielded['ismine'] ?? false) !== true
            || ($shielded['type'] ?? '') !== 'sapling') {
            $this->ledger->hold($batch, 'Pool spending keys or Sapling address validation failed');
            return 'held';
        }
        foreach ($batch['items'] as $item) {
            $valid = $this->call('validateaddress', [$item['address']]);
            if (($valid['isvalid'] ?? false) !== true) {
                $this->ledger->hold($batch, 'Reserved recipient is not a valid transparent ZCL address');
                return 'held';
            }
        }
        $available = ZclAmount::parse($this->call('z_getbalance', [$cfg['pool_zaddress'], $cfg['confirmations']]));
        $fee = (float) ZclAmount::decimal($cfg['fee_zat']);
        if (($batch['purpose'] ?? 'miners') === 'operator') {
            if (!$this->ledger->canSendOperator($batch, $available)) {
                $this->ledger->cancelUnsentOperator($batch);
                return 'operator-waiting-for-surplus';
            }
            return $this->submit($batch, 'send', 'z_sendmany', [$cfg['pool_zaddress'], [
                ['address' => $batch['operator_address'], 'amount' => ZclAmount::decimal((int) $batch['amount_zat'])],
            ], $cfg['confirmations'], $fee], $available);
        }

        if ($available >= (int) $batch['amount_zat'] + $cfg['fee_zat']) {
            $recipients = array_map(static function ($item) {
                // ZCL AmountFromValue explicitly accepts strings; this preserves
                // all eight decimal places without binary float round trips.
                return ['address' => $item['address'], 'amount' => ZclAmount::decimal((int) $item['amount_zat'])];
            }, $batch['items']);
            return $this->submit($batch, 'send', 'z_sendmany', [$cfg['pool_zaddress'], $recipients, $cfg['confirmations'], $fee]);
        }
        $coins = $this->call('listunspent', [$cfg['coinbase_confirmations'], 9999999, [$cfg['pool_taddress']]]);
        $mature = 0;
        $count = 0;
        foreach ($coins as $coin) {
            if (($coin['generated'] ?? false) !== true || ($coin['spendable'] ?? false) !== true
                || ($coin['address'] ?? '') !== $cfg['pool_taddress']
                || (int) ($coin['confirmations'] ?? 0) < $cfg['coinbase_confirmations']) continue;
            $mature += ZclAmount::parse($coin['amount']);
            if (++$count >= $cfg['shield_limit']) break;
        }
        // Avoid constructing a zero/fee-only shield operation. The pool reserve
        // covers network fees; miner balances are never silently cut.
        if ($mature <= 2 * $cfg['fee_zat']) return 'waiting-for-funding';
        return $this->submit($batch, 'shield', 'z_shieldcoinbase', [$cfg['pool_taddress'], $cfg['pool_zaddress'], $fee, $cfg['shield_limit']]);
    }

    private function submit(array $batch, string $kind, string $method, array $params, ?int $shieldedAvailable = null): string
    {
        $id = $this->ledger->prepareOperation($batch, $kind, ['method' => $method, 'params' => $params], $shieldedAvailable);
        try {
            $result = $this->call($method, $params);
            $opid = $kind === 'shield' ? ($result['opid'] ?? null) : $result;
            if (!is_string($opid) || !preg_match('/^opid-[a-zA-Z0-9-]{8,120}$/D', $opid)) throw new RuntimeException('Wallet did not return a valid operation id');
            $this->ledger->recordOperationId($id, $opid);
            return $kind . '-submitted';
        } catch (Throwable $e) {
            // The reservation and submitted intent survive even if recording this
            // hold also fails. On restart, submitting becomes held before any RPC.
            $current = $this->ledger->active();
            if ($current) $this->ledger->hold($current, 'Unknown submission outcome: ' . $e->getMessage());
            return 'held';
        }
    }

    private function watch(array $batch): string
    {
        $op = $batch['operation'];
        if (!$op || $op['state'] !== 'watching' || !$op['opid']) throw new RuntimeException('Invalid operation journal');
        // Never use z_getoperationresult: it removes completed operations.
        $results = $this->call('z_getoperationstatus', [[$op['opid']]]);
        if (!is_array($results) || count($results) !== 1 || ($results[0]['id'] ?? '') !== $op['opid']) {
            $this->ledger->hold($batch, 'Wallet operation missing after restart or status mismatch; reconcile before proceeding');
            return 'held';
        }
        $result = $results[0];
        if (in_array($result['status'] ?? '', ['queued', 'executing'], true)) return 'operation-pending';
        if (($result['status'] ?? '') !== 'success' || !is_string($result['result']['txid'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $result['result']['txid'])) {
            $this->ledger->hold($batch, 'Wallet operation failed or returned an invalid transaction; reservation retained');
            return 'held';
        }
        $this->ledger->recordTransaction($batch, $result['result']['txid']);
        return 'broadcast';
    }

    private function confirm(array $batch): string
    {
        $op = $batch['operation'];
        if (!$op || $op['state'] !== 'broadcast' || !$op['txid']) throw new RuntimeException('Invalid transaction journal');
        $tx = $this->call('gettransaction', [$op['txid']]);
        if (($tx['txid'] ?? '') !== $op['txid']) {
            $this->ledger->hold($batch, 'Wallet transaction identity mismatch');
            return 'held';
        }
        $confirmations = (int) ($tx['confirmations'] ?? 0);
        if ($confirmations < 0 || !empty($tx['walletconflicts'])) {
            $this->ledger->hold($batch, 'Payout transaction conflicted or was orphaned');
            return 'held';
        }
        if ($confirmations < $this->config['confirmations']) return 'confirmations-pending';
        $this->ledger->confirm($batch);
        return $op['kind'] === 'send' ? 'complete' : 'shield-confirmed';
    }
}
