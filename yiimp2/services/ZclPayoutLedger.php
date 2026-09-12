<?php
namespace app\services;

use PDO;
use RuntimeException;
use Throwable;

/** Durable reservations and RPC intent journal. No wallet calls occur in this class. */
class ZclPayoutLedger
{
    private PDO $db;
    private int $coinId;
    private ?string $lease = null;
    private string $lock;

    public function __construct(PDO $db, int $coinId)
    {
        $this->db = $db;
        $this->coinId = $coinId;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) throw new RuntimeException('Unsupported ledger database');
        $this->lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function transaction(callable $fn)
    {
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function lockEnabledCoin(): void
    {
        $coin = $this->run('SELECT symbol,enable FROM coins WHERE id=?' . $this->lock, [$this->coinId])->fetch(PDO::FETCH_ASSOC);
        if (!$coin || strtoupper($coin['symbol']) !== 'ZCL' || !(int) $coin['enable']) throw new RuntimeException('ZCL coin is not enabled');
    }

    public function accountingHeld(): bool
    {
        // Missing hold table is an installation error, never implicit permission.
        return (bool) $this->run('SELECT 1 FROM zcl_accounting_holds WHERE coin_id=?', [$this->coinId])->fetchColumn();
    }

    /** Recheck recently completed sends before authorizing a fresh reservation. */
    public function recentConfirmedSends(): array
    {
        return $this->run("SELECT O.txid FROM zcl_payment_operations O JOIN zcl_payment_batches B ON B.id=O.batch_id WHERE B.coin_id=? AND O.kind='send' AND O.state='confirmed' AND O.updated_at>=? ORDER BY O.updated_at DESC LIMIT 1000", [$this->coinId, time() - 86400])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function holdAccounting(string $reason): void
    {
        $this->transaction(function () use ($reason) {
            $this->assertLease();
            $this->lockEnabledCoin();
            $insert = $this->lock === '' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $this->run("{$insert} INTO zcl_accounting_holds (coin_id,reason,created_at) VALUES (?,?,?)", [$this->coinId, substr($reason, 0, 255), time()]);
        });
    }

    public function claim(): bool
    {
        $insert = $this->lock === '' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->run("{$insert} INTO zcl_payment_control (coin_id,lease_until) VALUES (?,0)", [$this->coinId]);
        $token = bin2hex(random_bytes(16));
        $n = $this->run('UPDATE zcl_payment_control SET lease_token=?,lease_until=? WHERE coin_id=? AND lease_until<?',
            [$token, time() + 180, $this->coinId, time()])->rowCount();
        if ($n === 1) $this->lease = $token;
        return $n === 1;
    }

    public function release(): void
    {
        if ($this->lease !== null) {
            $this->run('UPDATE zcl_payment_control SET lease_token=NULL,lease_until=0 WHERE coin_id=? AND lease_token=?', [$this->coinId, $this->lease]);
            $this->lease = null;
        }
    }

    private function assertLease(): array
    {
        $row = $this->run('SELECT * FROM zcl_payment_control WHERE coin_id=?' . $this->lock, [$this->coinId])->fetch(PDO::FETCH_ASSOC);
        if (!$row || $this->lease === null || $row['lease_token'] !== $this->lease || (int) $row['lease_until'] < time()) {
            throw new RuntimeException('Lost payout worker lease; no further state changes permitted');
        }
        return $row;
    }

    public function active(): ?array
    {
        $row = $this->run('SELECT B.* FROM zcl_payment_batches B JOIN zcl_payment_control C ON C.active_batch=B.id WHERE C.coin_id=?', [$this->coinId])->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['items'] = $this->run('SELECT * FROM zcl_payment_items WHERE batch_id=? ORDER BY account_id', [$row['id']])->fetchAll(PDO::FETCH_ASSOC);
        if (($row['purpose'] ?? 'miners') === 'operator') {
            $row['items'] = [['address' => $row['operator_address'], 'amount_zat' => $row['amount_zat']]];
        }
        $row['config'] = json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['operation'] = $row['active_operation'] ? $this->run('SELECT * FROM zcl_payment_operations WHERE id=?', [$row['active_operation']])->fetch(PDO::FETCH_ASSOC) : null;
        return $row;
    }

    public function reserve(array $config): ?array
    {
        $this->transaction(function () use ($config) {
            $control = $this->assertLease();
            if ($control['active_batch']) return;
            $this->lockEnabledCoin();
            if ($this->accountingHeld()) throw new RuntimeException('Accounting hold prevents payout reservation');
            if ($this->run("SELECT 1 FROM payouts WHERE idcoin=? AND (tx IS NULL OR tx='') LIMIT 1", [$this->coinId])->fetchColumn()) {
                throw new RuntimeException('Unresolved legacy payout prevents new reservations');
            }
            $minimum = ZclAmount::decimal($config['minimum_zat']);
            $limit = (int) $config['max_recipients'];
            $users = $this->run("SELECT id,username,balance,payout_threshold FROM accounts WHERE coinid=? AND is_locked=0 AND balance>=? AND (payout_threshold IS NULL OR balance>=payout_threshold) ORDER BY id LIMIT {$limit}" . $this->lock, [$this->coinId, $minimum])->fetchAll(PDO::FETCH_ASSOC);
            if (!$users) return;
            $batchId = bin2hex(random_bytes(16));
            $total = 0;
            foreach ($users as $user) {
                $amount = ZclAmount::parse($user['balance']);
                if ($amount <= 0) throw new RuntimeException('Nonpositive payout reservation');
                $total += $amount;
                if ($total > ZclAmount::MAX) throw new RuntimeException('Payout total exceeds supply');
            }
            $now = time();
            $this->run('INSERT INTO zcl_payment_batches (id,coin_id,state,amount_zat,config_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                [$batchId, $this->coinId, 'funding', $total, json_encode($config, JSON_THROW_ON_ERROR), $now, $now]);
            foreach ($users as $user) {
                $amount = ZclAmount::parse($user['balance']);
                $decimal = ZclAmount::decimal($amount);
                $n = $this->run('UPDATE accounts SET balance=balance-? WHERE id=? AND coinid=? AND balance>=?', [$decimal, $user['id'], $this->coinId, $decimal])->rowCount();
                if ($n !== 1) throw new RuntimeException('Account reservation failed');
                $this->run('INSERT INTO payouts (account_id,idcoin,time,completed,amount,fee,errmsg) VALUES (?,?,?,0,?,0,?)', [$user['id'], $this->coinId, $now, $decimal, 'Reserved in ZCL batch ' . $batchId]);
                $payoutId = $this->db->lastInsertId();
                $this->run('INSERT INTO zcl_payment_items (batch_id,account_id,address,amount_zat,payout_id) VALUES (?,?,?,?,?)', [$batchId, $user['id'], $user['username'], $amount, $payoutId]);
            }
            $this->run('UPDATE zcl_payment_control SET active_batch=? WHERE coin_id=?', [$batchId, $this->coinId]);
        });
        return $this->active();
    }

    private function zatoshis($value): int
    {
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]{0,15})$/D', $value) || (int) $value > ZclAmount::MAX) {
            throw new RuntimeException('Invalid exact journal amount');
        }
        return (int) $value;
    }

    /** Source fees are independent of donations and never guessed for legacy rounds. */
    public function creditMatureOperatorFees(): int
    {
        if ($this->accountingHeld()) return 0;
        $ids = $this->run("SELECT R.block_id FROM zcl_reward_rounds R JOIN blocks B ON B.id=R.block_id AND B.coin_id=R.coin_id AND B.blockhash=R.blockhash LEFT JOIN zcl_operator_credits C ON C.block_id=R.block_id WHERE R.coin_id=? AND R.fee_sat>0 AND B.category='generate' AND B.confirmations>=101 AND C.block_id IS NULL ORDER BY R.block_id LIMIT 1000", [$this->coinId])->fetchAll(PDO::FETCH_COLUMN);
        $credited = 0;
        foreach ($ids as $id) {
            $credited += $this->transaction(function () use ($id) {
                $this->assertLease();
                $this->lockEnabledCoin();
                if ($this->accountingHeld()) return 0;
                $row = $this->run("SELECT R.* FROM zcl_reward_rounds R JOIN blocks B ON B.id=R.block_id AND B.coin_id=R.coin_id AND B.blockhash=R.blockhash WHERE R.block_id=? AND R.coin_id=? AND R.fee_sat>0 AND B.category='generate' AND B.confirmations>=101" . $this->lock, [$id, $this->coinId])->fetch(PDO::FETCH_ASSOC);
                if (!$row || $this->run('SELECT 1 FROM zcl_operator_credits WHERE block_id=?', [$id])->fetchColumn()) return 0;
                $amount = $this->zatoshis($row['fee_sat']);
                $retained = $this->zatoshis($row['retained_sat']);
                if ($amount > $retained || $this->zatoshis($row['credited_sat']) + $retained !== $this->zatoshis($row['reward_sat'])) {
                    throw new RuntimeException('Operator source round does not conserve its reward');
                }
                $this->run('INSERT INTO zcl_operator_credits (block_id,coin_id,amount_zat,created_at) VALUES (?,?,?,?)', [$id, $this->coinId, $amount, time()]);
                return 1;
            });
        }
        return $credited;
    }

    private function invalidOperatorCredit(): bool
    {
        return (bool) $this->run("SELECT 1 FROM zcl_operator_credits C LEFT JOIN zcl_reward_rounds R ON R.block_id=C.block_id AND R.coin_id=C.coin_id LEFT JOIN blocks B ON B.id=C.block_id AND B.coin_id=C.coin_id WHERE C.coin_id=? AND (R.block_id IS NULL OR B.id IS NULL OR B.blockhash<>R.blockhash OR B.category<>'generate' OR B.confirmations<101 OR R.fee_sat IS NULL OR C.amount_zat<>R.fee_sat OR R.fee_sat>R.retained_sat OR R.reward_sat<>R.credited_sat+R.retained_sat) LIMIT 1", [$this->coinId])->fetchColumn();
    }

    /** Retain the credited journal and hold the coin when its backing block changes. */
    public function auditOperatorCredits(): bool
    {
        return $this->transaction(function () {
            $this->assertLease();
            $this->lockEnabledCoin();
            if ($this->invalidOperatorCredit()) {
                $insert = $this->lock === '' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
                $this->run("{$insert} INTO zcl_accounting_holds (coin_id,reason,created_at) VALUES (?,?,?)", [$this->coinId, 'Mature operator fee source changed; reconciliation required', time()]);
            }
            return !$this->accountingHeld();
        });
    }

    /** All unpaid miner claims, including locked/below-threshold and immature earnings. */
    private function minerLiabilities(): int
    {
        $total = 0;
        foreach ([['SELECT balance FROM accounts WHERE coinid=?', $this->coinId],
            ['SELECT amount FROM earnings WHERE coinid=? AND status IN (0,1)', $this->coinId],
            ['SELECT amount FROM payouts WHERE idcoin=? AND completed=0', $this->coinId]] as [$sql, $coin]) {
            foreach ($this->run($sql, [$coin])->fetchAll(PDO::FETCH_COLUMN) as $amount) {
                $total += ZclAmount::parse($amount);
                if ($total > ZclAmount::MAX) throw new RuntimeException('Miner liabilities exceed supply');
            }
        }
        return $total;
    }

    /** Network fees are reserved at intent, including unknown/held operations. */
    private function operatorBudget(?string $excludeBatch = null): int
    {
        if ($this->run('SELECT 1 FROM zcl_payment_operations O JOIN zcl_payment_batches B ON B.id=O.batch_id WHERE B.coin_id=? AND O.network_fee_zat IS NULL LIMIT 1', [$this->coinId])->fetchColumn()) {
            throw new RuntimeException('Historical network fees require reconciliation before operator remittance');
        }
        $credits = $this->zatoshis($this->run('SELECT COALESCE(SUM(amount_zat),0) FROM zcl_operator_credits WHERE coin_id=?', [$this->coinId])->fetchColumn());
        $fees = $this->zatoshis($this->run('SELECT COALESCE(SUM(O.network_fee_zat),0) FROM zcl_payment_operations O JOIN zcl_payment_batches B ON B.id=O.batch_id WHERE B.coin_id=?', [$this->coinId])->fetchColumn());
        $sql = "SELECT COALESCE(SUM(amount_zat),0) FROM zcl_payment_batches WHERE coin_id=? AND purpose='operator' AND state<>'cancelled'";
        $params = [$this->coinId];
        if ($excludeBatch !== null) { $sql .= ' AND id<>?'; $params[] = $excludeBatch; }
        $remittances = $this->zatoshis($this->run($sql, $params)->fetchColumn());
        return $credits - $fees - $remittances;
    }

    /** Called only under the coin lock, directly before an operator send intent. */
    private function operatorSendCovered(array $batch, int $available): bool
    {
        if ($this->invalidOperatorCredit()) return false;
        $reserve = $batch['config']['operator_reserve_zat'];
        $fee = $batch['config']['fee_zat'];
        $amount = (int) $batch['amount_zat'];
        return $this->operatorBudget($batch['id']) >= $amount + $reserve + $fee
            && $available >= $amount + $this->minerLiabilities() + $reserve + $fee;
    }

    public function canSendOperator(array $batch, int $available): bool
    {
        return $this->transaction(function () use ($batch, $available) {
            $this->assertLease();
            $this->lockEnabledCoin();
            return !$this->accountingHeld() && $this->operatorSendCovered($batch, $available);
        });
    }

    /** Operator transfers never reserve miner balances or create miner payout rows. */
    public function reserveOperator(array $config, int $available): ?array
    {
        $this->transaction(function () use ($config, $available) {
            $control = $this->assertLease();
            if ($control['active_batch']) return;
            $this->lockEnabledCoin();
            if ($this->accountingHeld() || $this->invalidOperatorCredit()) throw new RuntimeException('Operator accounting is on hold');
            $reserve = $config['operator_reserve_zat'];
            $fee = $config['fee_zat'];
            $amount = min($this->operatorBudget() - $reserve - $fee, $available - $this->minerLiabilities() - $reserve - $fee);
            if ($amount < $config['operator_minimum_zat']) return;
            $id = bin2hex(random_bytes(16));
            $this->run('INSERT INTO zcl_payment_batches (id,coin_id,state,amount_zat,config_json,created_at,updated_at,purpose,operator_address) VALUES (?,?,?,?,?,?,?,?,?)',
                [$id, $this->coinId, 'funding', $amount, json_encode($config, JSON_THROW_ON_ERROR), time(), time(), 'operator', $config['operator_taddress']]);
            $this->run('UPDATE zcl_payment_control SET active_batch=? WHERE coin_id=?', [$id, $this->coinId]);
        });
        return $this->active();
    }

    /** Release only an owner reservation that has NEVER attempted a wallet mutation. */
    public function cancelUnsentOperator(array $batch): void
    {
        $this->transaction(function () use ($batch) {
            $this->assertLease();
            $this->lockEnabledCoin();
            if ($this->run('SELECT 1 FROM zcl_payment_operations WHERE batch_id=? LIMIT 1', [$batch['id']])->fetchColumn()) throw new RuntimeException('Attempted operation cannot be cancelled or refunded');
            $n = $this->run("UPDATE zcl_payment_batches SET state='cancelled',updated_at=? WHERE id=? AND purpose='operator' AND state='funding' AND active_operation IS NULL", [time(), $batch['id']])->rowCount();
            if ($n !== 1) throw new RuntimeException('Operator reservation cannot be cancelled');
            $this->run('UPDATE zcl_payment_control SET active_batch=NULL WHERE coin_id=? AND active_batch=?', [$this->coinId, $batch['id']]);
        });
    }

    /** Commit intent BEFORE any non-idempotent RPC. A stranded submitting state is never retried. */
    public function prepareOperation(array $batch, string $kind, array $request, ?int $shieldedAvailable = null): string
    {
        return $this->transaction(function () use ($batch, $kind, $request, $shieldedAvailable) {
            $this->assertLease();
            $this->lockEnabledCoin();
            if ($this->accountingHeld()) throw new RuntimeException('Accounting hold prevents wallet submission');
            if (($batch['purpose'] ?? 'miners') === 'operator') {
                if ($kind !== 'send' || $shieldedAvailable === null || !$this->operatorSendCovered($batch, $shieldedAvailable)) {
                    throw new RuntimeException('Operator transfer would consume miner liabilities or its fee reserve');
                }
            }
            $opId = bin2hex(random_bytes(16));
            $n = $this->run("UPDATE zcl_payment_batches SET state='operation',active_operation=?,updated_at=? WHERE id=? AND state='funding'", [$opId, time(), $batch['id']])->rowCount();
            if ($n !== 1) throw new RuntimeException('Payout state changed before submission');
            $this->run('INSERT INTO zcl_payment_operations (id,batch_id,kind,state,request_json,network_fee_zat,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
                [$opId, $batch['id'], $kind, 'submitting', json_encode($request, JSON_THROW_ON_ERROR), $batch['config']['fee_zat'], time(), time()]);
            return $opId;
        });
    }

    public function recordOperationId(string $id, string $opid): void
    {
        $this->transaction(function () use ($id, $opid) {
            $this->assertLease();
            $n = $this->run("UPDATE zcl_payment_operations SET state='watching',opid=?,updated_at=? WHERE id=? AND state='submitting'", [$opid, time(), $id])->rowCount();
            if ($n !== 1) throw new RuntimeException('Operation outcome could not be recorded');
        });
    }

    public function recordTransaction(array $batch, string $txid): void
    {
        $this->transaction(function () use ($batch, $txid) {
            $this->assertLease();
            $n = $this->run("UPDATE zcl_payment_operations SET state='broadcast',txid=?,updated_at=? WHERE id=? AND state='watching'", [$txid, time(), $batch['active_operation']])->rowCount();
            if ($n !== 1) throw new RuntimeException('Operation transaction state changed');
            $this->run("UPDATE zcl_payment_batches SET state='confirming',updated_at=? WHERE id=? AND state='operation'", [time(), $batch['id']]);
            if ($batch['operation']['kind'] === 'send') {
                $this->run('UPDATE payouts SET tx=?,errmsg=NULL WHERE id IN (SELECT payout_id FROM zcl_payment_items WHERE batch_id=?)', [$txid, $batch['id']]);
            }
        });
    }

    public function confirm(array $batch): void
    {
        $this->transaction(function () use ($batch) {
            $this->assertLease();
            $n = $this->run("UPDATE zcl_payment_operations SET state='confirmed',updated_at=? WHERE id=? AND state='broadcast'", [time(), $batch['active_operation']])->rowCount();
            if ($n !== 1) throw new RuntimeException('Operation already confirmed or changed');
            $done = $batch['operation']['kind'] === 'send';
            $this->run('UPDATE zcl_payment_batches SET state=?,active_operation=NULL,updated_at=? WHERE id=?', [$done ? 'complete' : 'funding', time(), $batch['id']]);
            if ($done) {
                $this->run('UPDATE payouts SET completed=1 WHERE id IN (SELECT payout_id FROM zcl_payment_items WHERE batch_id=?)', [$batch['id']]);
                $this->run('UPDATE zcl_payment_control SET active_batch=NULL WHERE coin_id=? AND active_batch=?', [$this->coinId, $batch['id']]);
            }
        });
    }

    public function hold(array $batch, string $reason): void
    {
        $this->transaction(function () use ($batch, $reason) {
            $this->assertLease();
            $this->run("UPDATE zcl_payment_batches SET state='held',error=?,updated_at=? WHERE id=?", [$reason, time(), $batch['id']]);
            if ($batch['active_operation']) {
                $this->run("UPDATE zcl_payment_operations SET state='held',error=?,updated_at=? WHERE id=?", [$reason, time(), $batch['active_operation']]);
            }
            $this->run('UPDATE payouts SET errmsg=? WHERE id IN (SELECT payout_id FROM zcl_payment_items WHERE batch_id=?)', [$reason, $batch['id']]);
        });
    }

    /** Credit each mature earning exactly once, retaining its ledger record. */
    public function creditMatureEarnings(int $before, int $confirmations = 101): int
    {
        if ($confirmations < 101) throw new RuntimeException('ZCL wallet coinbase maturity requires 101 confirmations');
        if ($this->accountingHeld()) return 0;
        $ids = $this->run("SELECT E.id FROM earnings E JOIN blocks B ON B.id=E.blockid AND B.coin_id=E.coinid WHERE E.coinid=? AND E.status=1 AND E.mature_time<? AND B.category='generate' AND B.confirmations>=? ORDER BY E.id LIMIT 1000", [$this->coinId, $before, $confirmations])->fetchAll(PDO::FETCH_COLUMN);
        $credited = 0;
        foreach ($ids as $id) {
            $credited += $this->transaction(function () use ($id, $before, $confirmations) {
                $this->lockEnabledCoin();
                if ($this->accountingHeld()) return 0;
                $earning = $this->run("SELECT E.* FROM earnings E JOIN blocks B ON B.id=E.blockid AND B.coin_id=E.coinid WHERE E.id=? AND E.coinid=? AND E.status=1 AND E.mature_time<? AND B.category='generate' AND B.confirmations>=?" . $this->lock, [$id, $this->coinId, $before, $confirmations])->fetch(PDO::FETCH_ASSOC);
                if (!$earning) return 0;
                $account = $this->run('SELECT id,coinid FROM accounts WHERE id=?' . $this->lock, [$earning['userid']])->fetch(PDO::FETCH_ASSOC);
                if (!$account || (int) $account['coinid'] !== $this->coinId) throw new RuntimeException('Earning account missing or assigned to another coin');
                $amount = ZclAmount::decimal(ZclAmount::parse($earning['amount']));
                $this->run('UPDATE accounts SET balance=balance+? WHERE id=? AND coinid=?', [$amount, $account['id'], $this->coinId]);
                $n = $this->run('UPDATE earnings SET status=2,price=0 WHERE id=? AND status=1', [$id])->rowCount();
                if ($n !== 1) throw new RuntimeException('Concurrent earning credit detected');
                return 1;
            });
        }
        return $credited;
    }
}
