<?php
namespace app\services;

use RuntimeException;
use Throwable;

/** Read-only pool reward reporting; never credits balances or submits wallet RPCs. */
final class ZclMinedStats
{
    public const MAX_ROWS = 10000;

    public static function unavailable(int $now): array
    {
        return [
            'schemaVersion'=>1, 'asset'=>'ZCL', 'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z', $now),
            'status'=>'unavailable', 'coverageStartedAt'=>null, 'coverageBasis'=>'retained-pool-ledger',
            'windowBasis'=>'pool-recorded-time', 'rewardBasis'=>'gross-coinbase-including-fees',
            'allTime'=>null, 'last24h'=>null, 'lastHour'=>null,
            'unknownBlocks'=>null, 'excludedOrphans'=>null, 'accountingHeld'=>null,
        ];
    }

    private static function emptyWindow(): array
    {
        return ['rewardZat'=>'0', 'blocks'=>0, 'matureRewardZat'=>'0', 'matureBlocks'=>0,
            'immatureRewardZat'=>'0', 'immatureBlocks'=>0];
    }

    private static function amount($value): string
    {
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('Invalid exact amount');
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]{0,23})$/D', $value)) throw new RuntimeException('Invalid exact amount');
        return $value;
    }

    /**
     * Rows come only from ZCL blocks LEFT JOIN the immutable reward journal.
     * The callback gets getblockheader(hash) results from the existing loopback node.
     * A finite deadline stops further RPC calls; unchecked candidates stay unknown.
     */
    public static function summarize(array $rows, callable $headerForHash, int $now,
        bool $accountingHeld = false, int $missingBlocks = 0, ?float $deadline = null): array
    {
        if (!extension_loaded('bcmath') || count($rows) > self::MAX_ROWS || $missingBlocks < 0) {
            return self::unavailable($now);
        }
        $result = self::unavailable($now);
        $result['status'] = 'ok';
        $result['accountingHeld'] = $accountingHeld;
        $result['unknownBlocks'] = $missingBlocks;
        $result['excludedOrphans'] = 0;
        foreach (['allTime','last24h','lastHour'] as $window) $result[$window] = self::emptyWindow();

        // The round's unique (coin_id, blockhash) identifies one allocated block.
        // Stratum may briefly insert a duplicate before the normal worker removes it.
        $groups = [];
        foreach ($rows as $row) {
            $hash = $row['blockhash'] ?? null;
            if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/D', $hash)) {
                ++$result['unknownBlocks'];
                continue;
            }
            $groups[$hash][] = $row;
        }
        foreach ($groups as $hash => $duplicates) {
            $journaled = array_values(array_filter($duplicates, static fn($r) =>
                ($r['round_hash'] ?? null) === $hash && (string)($r['round_block_id'] ?? '') === (string)($r['id'] ?? '')));
            if (count($journaled) === 1) $row = $journaled[0];
            elseif (count($duplicates) === 1) $row = $duplicates[0];
            else { ++$result['unknownBlocks']; continue; }

            if (in_array($row['category'] ?? null, ['orphan','rejected'], true)) {
                try {
                    if ($deadline !== null && microtime(true) >= $deadline) throw new RuntimeException('Read budget exhausted');
                    $header = $headerForHash($hash);
                    if (!is_array($header) || ($header['hash'] ?? null) !== $hash
                        || !is_int($header['confirmations'] ?? null)
                        || !is_int($header['height'] ?? null) || $header['height'] < 0
                        || ((int)($row['height'] ?? 0) > 0 && (int)$row['height'] !== $header['height'])
                        || $header['confirmations'] >= 0) {
                        // A restored canonical block disagrees with the ledger;
                        // neither count nor silently exclude it pending reconciliation.
                        throw new RuntimeException('Orphan status unverified');
                    }
                    ++$result['excludedOrphans'];
                } catch (Throwable $e) {
                    ++$result['unknownBlocks'];
                }
                continue;
            }
            try {
                if (!in_array($row['category'] ?? null, ['immature','generate'], true)
                    || ($row['round_hash'] ?? null) !== $hash
                    || (string)($row['round_block_id'] ?? '') !== (string)($row['id'] ?? '')
                    || !preg_match('/^[1-9][0-9]*$/D', (string)($row['time'] ?? ''))
                    || (int)$row['time'] > $now) throw new RuntimeException('Unresolved pool block');
                $reward = self::amount($row['reward_sat'] ?? null);
                $credited = self::amount($row['credited_sat'] ?? null);
                $retained = self::amount($row['retained_sat'] ?? null);
                if (bccomp($reward, '0', 0) <= 0 || bcadd($credited, $retained, 0) !== $reward
                    || ZclRewardLedger::satoshis((string)($row['amount'] ?? '')) !== $reward) {
                    throw new RuntimeException('Reward evidence disagrees');
                }
                if ($deadline !== null && microtime(true) >= $deadline) throw new RuntimeException('Read budget exhausted');
                $header = $headerForHash($hash);
                if (!is_array($header) || ($header['hash'] ?? null) !== $hash
                    || !is_int($header['confirmations'] ?? null)
                    || !is_int($header['height'] ?? null) || $header['height'] < 0
                    || ((int)($row['height'] ?? 0) > 0 && (int)$row['height'] !== $header['height'])) {
                    throw new RuntimeException('Block header evidence unavailable');
                }
                if ($header['confirmations'] < 0) { ++$result['excludedOrphans']; continue; }
                if ($header['confirmations'] < 1) throw new RuntimeException('Block not accepted');
                $maturity = $row['category'] === 'generate' && $header['confirmations'] >= 101 ? 'mature' : 'immature';
                foreach (['allTime'=>null, 'last24h'=>86400, 'lastHour'=>3600] as $window => $seconds) {
                    // Rolling windows are (now - width, now], in UTC.
                    if ($seconds !== null && (int)$row['time'] <= $now - $seconds) continue;
                    $result[$window]['rewardZat'] = bcadd($result[$window]['rewardZat'], $reward, 0);
                    ++$result[$window]['blocks'];
                    $result[$window][$maturity.'RewardZat'] = bcadd($result[$window][$maturity.'RewardZat'], $reward, 0);
                    ++$result[$window][$maturity.'Blocks'];
                }
            } catch (Throwable $e) {
                ++$result['unknownBlocks'];
            }
        }
        if ($result['unknownBlocks'] > 0 || $accountingHeld) $result['status'] = 'partial';
        return $result;
    }
}
