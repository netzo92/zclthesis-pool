<?php

namespace app\services;

use PDO;
use RuntimeException;
use Throwable;

/** Native ZCL accounting. All monetary arithmetic is integer satoshis. */
final class ZclRewardLedger
{
    public function __construct(private PDO $db)
    {
        if (!extension_loaded('bcmath')) {
            throw new RuntimeException('ZCL accounting requires BCMath');
        }
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function satoshis(string $amount): string
    {
        if (!preg_match('/^\d{1,16}(?:\.\d{1,8})?$/D', $amount)) {
            throw new RuntimeException('Invalid exact ZCL amount');
        }
        return bcmul($amount, '100000000', 0);
    }

    public static function coins(string $satoshis): string
    {
        return bcdiv($satoshis, '100000000', 8);
    }

    /** Largest remainder split, with stable user-id tie breaking. */
    public static function split(string $reward, array $weights): array
    {
        if (!preg_match('/^\d+$/D', $reward) || !$weights) {
            throw new RuntimeException('Invalid reward allocation');
        }
        $total = '0';
        foreach ($weights as $id => $weight) {
            if ($id <= 0 || !preg_match('/^\d+$/D', $weight) || bccomp($weight, '0') <= 0) {
                throw new RuntimeException('Invalid share weight');
            }
            $total = bcadd($total, $weight, 0);
        }
        $allocated = '0';
        $result = $remainders = [];
        foreach ($weights as $id => $weight) {
            $numerator = bcmul($reward, $weight, 0);
            $result[$id] = bcdiv($numerator, $total, 0);
            $remainders[$id] = bcsub($numerator, bcmul($result[$id], $total, 0), 0);
            $allocated = bcadd($allocated, $result[$id], 0);
        }
        $ids = array_keys($weights);
        usort($ids, static fn($a, $b) => bccomp($remainders[$b], $remainders[$a], 0) ?: $a <=> $b);
        $remaining = (int) bcsub($reward, $allocated, 0); // Strictly less than count(weights).
        foreach (array_slice($ids, 0, $remaining) as $id) {
            $result[$id] = bcadd($result[$id], '1', 0);
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private static function afterFee(string $amount, string $percent): string
    {
        if (!preg_match('/^\d{1,3}(?:\.\d{1,8})?$/D', $percent) || bccomp($percent, '100', 8) > 0) {
            throw new RuntimeException('Fee must be between zero and 100 percent');
        }
        // Round the fee down, leaving fractional satoshis with the miner.
        return bcsub($amount, bcdiv(bcmul($amount, $percent, 8), '100', 0), 0);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** Preserve allocation history across maturity and reorg transitions. */
    public function transition(int $coinId, int $blockId, string $category, int $confirmations): void
    {
        if ($this->db->inTransaction()) throw new RuntimeException('Nested accounting transaction');
        $this->db->beginTransaction();
        try {
            $this->query('SELECT id FROM coins WHERE id=? FOR UPDATE', [$coinId])->fetchColumn();
            $block = $this->query('SELECT * FROM blocks WHERE id=? AND coin_id=? FOR UPDATE', [$blockId, $coinId])
                ->fetch(PDO::FETCH_ASSOC);
            if (!$block) throw new RuntimeException('Missing block for accounting transition');
            $credited = $this->query('SELECT id FROM earnings WHERE blockid=? AND status=2 FOR UPDATE', [$blockId])->fetchColumn();
            if ($credited && ($category !== 'generate' || $confirmations < 101)) {
                $this->query('INSERT INTO zcl_accounting_holds (coin_id,reason,created_at) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE reason=VALUES(reason)',
                    [$coinId, "Reorg after crediting block {$blockId}; reconciliation required", time()]);
            }
            $status = $category === 'generate' ? 1 : ($category === 'immature' ? 0 : -1);
            $this->query('UPDATE earnings SET status=?, mature_time=? WHERE blockid=? AND status<>2',
                [$status, $status === 1 ? time() : null, $blockId]);
            $this->query('UPDATE blocks SET category=?, confirmations=? WHERE id=?', [$category, $confirmations, $blockId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** Returns false when already committed or when the share window is empty. */
    public function allocate(int $coinId, int $blockId, bool $solo, string $fee): bool
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('ZCL allocation requires its own transaction');
        }
        $this->db->beginTransaction();
        try {
            // Serialize all rounds for this coin before selecting a share window.
            $coin = $this->query('SELECT * FROM coins WHERE id=? FOR UPDATE', [$coinId])->fetch(PDO::FETCH_ASSOC);
            $block = $this->query('SELECT * FROM blocks WHERE id=? FOR UPDATE', [$blockId])->fetch(PDO::FETCH_ASSOC);
            if (!$coin || strtoupper($coin['symbol']) !== 'ZCL' || $coin['auto_exchange'] || !$block ||
                (int) $block['coin_id'] !== $coinId || $block['algo'] !== $coin['algo'] ||
                !in_array($block['category'], ['immature', 'generate'], true) ||
                !preg_match('/^[a-fA-F0-9]{64}$/D', $block['blockhash'] ?? '') || (int) $block['height'] <= 0) {
                throw new RuntimeException('ZCL allocation requires a validated native-coin block');
            }
            if ($this->query('SELECT 1 FROM zcl_accounting_holds WHERE coin_id=?', [$coinId])->fetchColumn()) {
                throw new RuntimeException('ZCL accounting is on hold');
            }
            if ($this->query('SELECT 1 FROM zcl_reward_rounds WHERE coin_id=? AND blockhash=?',
                [$coinId, $block['blockhash']])->fetchColumn()) {
                $this->db->commit();
                return false;
            }
            // Different queue workers may race on different ZCL blocks. A
            // later block must not consume an earlier pending round's shares.
            if ($this->query("SELECT 1 FROM blocks b WHERE b.coin_id=? AND b.height<?
                AND b.category IN ('new','immature','generate')
                AND NOT EXISTS (SELECT 1 FROM zcl_reward_rounds r WHERE r.coin_id=b.coin_id AND r.blockhash=b.blockhash)
                LIMIT 1", [$coinId, $block['height']])->fetchColumn()) {
                $this->db->commit();
                return false;
            }
            if ($this->query('SELECT 1 FROM earnings WHERE blockid=? LIMIT 1', [$blockId])->fetchColumn()) {
                throw new RuntimeException('Unjournaled legacy earnings require reconciliation');
            }
            $reward = self::satoshis((string) $block['amount']);
            if (bccomp($reward, '0') <= 0) {
                throw new RuntimeException('Block reward must be positive');
            }
            $where = 'coinid=:coin AND algo=:algo AND blocknumber<=:height AND time<=:time
                AND blockrewarded IS NULL AND valid=1 AND COALESCE(solo,0)=:solo';
            $params = [':coin' => $coinId, ':algo' => $coin['algo'], ':height' => $block['height'],
                ':time' => $block['time'], ':solo' => (int) $solo];
            if ($solo) {
                $where .= ' AND userid=:finder';
                $params[':finder'] = $block['userid'];
            }
            // Lock the exact snapshot; late flushed shares remain for the next round.
            $shares = $this->query("SELECT id, userid, CAST(difficulty AS DECIMAL(50,24)) AS weight
                FROM shares WHERE {$where} ORDER BY id FOR UPDATE", $params)->fetchAll(PDO::FETCH_ASSOC);
            if (!$shares) {
                $this->db->commit();
                return false;
            }
            $weights = [];
            foreach ($shares as $share) {
                $weight = bcmul($share['weight'], '1000000000000000000000000', 0);
                if (bccomp($weight, '0') <= 0 || (int) $share['userid'] <= 0) {
                    throw new RuntimeException('Nonpositive share difficulty or missing miner');
                }
                $id = (int) $share['userid'];
                $weights[$id] = bcadd($weights[$id] ?? '0', $weight, 0);
            }
            $gross = self::split($reward, $weights);
            $accounts = $feeWeights = [];
            $feeBase = '0';
            foreach ($gross as $id => $amount) {
                $account = $this->query('SELECT * FROM accounts WHERE id=? FOR UPDATE', [$id])->fetch(PDO::FETCH_ASSOC);
                if (!$account || (int) $account['coinid'] !== $coinId) {
                    throw new RuntimeException('Every ZCL miner must have a native ZCL payout account');
                }
                $accounts[$id] = $account;
                if (!$account['no_fees'] && bccomp($amount, '0') > 0) {
                    $feeWeights[$id] = $amount;
                    $feeBase = bcadd($feeBase, $amount, 0);
                }
            }
            // Charge the configured fee once per round, rounded down to whole
            // satoshis. At the launch fee of 0.8%, this is floor(reward * 80 / 10000).
            // Split that exact fee proportionally across non-exempt miners.
            $feeTotal = bcsub($feeBase, self::afterFee($feeBase, $fee), 0);
            $fees = $feeWeights ? self::split($feeTotal, $feeWeights) : [];
            $credited = '0';
            $now = time();
            foreach ($gross as $id => $amount) {
                $account = $accounts[$id];
                $amount = bcsub($amount, $fees[$id] ?? '0', 0);
                if (!empty($account['donation'])) {
                    $amount = self::afterFee($amount, (string) $account['donation']);
                }
                $credited = bcadd($credited, $amount, 0);
                $mature = $block['category'] === 'generate';
                $this->query('INSERT INTO earnings (userid,coinid,blockid,create_time,amount,price,status,mature_time)
                    VALUES (?,?,?,?,?,0,?,?)', [$id, $coinId, $blockId, $block['time'], self::coins($amount),
                    $mature ? 1 : 0, $mature ? $now : null]);
                $this->query('UPDATE accounts SET last_earning=? WHERE id=?', [$now, $id]);
            }
            $lastId = $shares[count($shares) - 1]['id'];
            // Same predicate and high-water mark as the locked selection; no other
            // miner's solo shares, invalid shares or future shares are consumed.
            $changed = $this->query("UPDATE shares SET pid=-1, blockrewarded=:rewarded
                WHERE {$where} AND id<=:lastid", $params + [':rewarded' => $block['height'], ':lastid' => $lastId])->rowCount();
            if ($changed !== count($shares)) {
                throw new RuntimeException('Share snapshot changed during allocation');
            }
            $totalWeight = array_reduce($weights, static fn($sum, $weight) => bcadd($sum, $weight, 0), '0');
            $difficulty = bcdiv($totalWeight, '1000000000000000000000000', 24);
            $effort = (float) $block['difficulty'] > 0 ? (float) $difficulty * 100 / (float) $block['difficulty'] : null;
            $this->query('UPDATE blocks SET solo=?, effort=? WHERE id=?', [(int) $solo, $effort, $blockId]);
            $this->query('INSERT INTO zcl_reward_rounds
                (block_id,coin_id,blockhash,reward_sat,credited_sat,retained_sat,share_count,last_share_id,difficulty,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)', [$blockId, $coinId, $block['blockhash'], $reward, $credited,
                bcsub($reward, $credited, 0), count($shares), $lastId, $difficulty, $now]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
