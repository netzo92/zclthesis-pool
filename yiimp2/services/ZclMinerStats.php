<?php
namespace app\services;

use PDO;
use RuntimeException;

/** Public, address-scoped, read-only pool accounting and accepted-work reporting. */
final class ZclMinerStats
{
    public const MAX_ROWS = 10000;
    public const SOLUTIONS_PER_WEIGHT = 65537.00001525902;

    public function __construct(private PDO $db) {}

    public static function address(string $address): string
    {
        if (!preg_match('/^t[13][1-9A-HJ-NP-Za-km-z]{33}$/D', $address)) throw new \InvalidArgumentException('Invalid address');
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $bytes = [0];
        foreach (str_split($address) as $char) {
            $carry = strpos($alphabet, $char);
            foreach ($bytes as &$byte) {
                $carry += $byte * 58;
                $byte = $carry & 255;
                $carry >>= 8;
            }
            unset($byte);
            while ($carry) { $bytes[] = $carry & 255; $carry >>= 8; }
        }
        $raw = implode('', array_map('chr', array_reverse($bytes)));
        if (strlen($raw) !== 26 || !in_array(substr($raw,0,2), ["\x1c\xb8","\x1c\xbd"], true)
            || !hash_equals(substr(hash('sha256', hash('sha256', substr($raw,0,-4), true), true),0,4), substr($raw,-4))) {
            throw new \InvalidArgumentException('Invalid address');
        }
        return $address;
    }

    private function rows(string $sql, array $params = []): array
    {
        $query = $this->db->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) throw new RuntimeException('Reporting limit reached');
        return $rows;
    }

    private static function amount($value): int
    {
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('Exact amount unavailable');
        return ZclAmount::parse($value);
    }

    private static function zat($value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', (string)$value)
            || (int)$value > ZclAmount::MAX) throw new RuntimeException('Invalid journal amount');
        return (int)$value;
    }

    private static function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > ZclAmount::MAX - $b) throw new RuntimeException('Amount bound exceeded');
        return $a + $b;
    }

    public static function network(?array $node, int $now): array
    {
        $result = ['status'=>'unavailable','generatedAt'=>null,'solps'=>null,'basis'=>'last-120-blocks'];
        if (($node['schemaVersion'] ?? null) !== 1 || ($node['asset'] ?? null) !== 'ZCL') return $result;
        $time = isset($node['generatedAt']) && is_string($node['generatedAt']) ? strtotime($node['generatedAt']) : false;
        $rate = $node['mining']['networkSolps'] ?? null;
        if ($time === false || $time > $now+60 || (!is_int($rate) && !is_float($rate))
            || !is_finite((float)$rate) || (float)$rate <= 0) return $result;
        $result['generatedAt'] = $node['generatedAt'];
        $result['solps'] = (float)$rate;
        $result['status'] = $time <= $now+60 && $time >= $now-180 && ($node['node']['synced'] ?? false) === true ? 'ok' : 'stale';
        return $result;
    }

    private function work(int $coin, ?int $account, int $now, array $network): array
    {
        $sql = 'SELECT time,difficulty FROM shares WHERE coinid=? AND algo=? AND valid=1 AND time>? AND time<=?';
        $params = [$coin,'equihash192',$now-3600,$now];
        if ($account !== null) { $sql .= ' AND userid=?'; $params[] = $account; }
        $rows = $this->rows($sql.' ORDER BY time DESC LIMIT '.(self::MAX_ROWS+1), $params);
        $weights = ['last5m'=>0.0,'lastHour'=>0.0];
        foreach ($rows as $row) {
            if (!is_numeric($row['difficulty']) || !is_finite((float)$row['difficulty']) || (float)$row['difficulty'] <= 0) {
                throw new RuntimeException('Invalid accepted work');
            }
            $weights['lastHour'] += (float)$row['difficulty'];
            if ((int)$row['time'] > $now-300) $weights['last5m'] += (float)$row['difficulty'];
        }
        $result = [];
        foreach (['last5m'=>300,'lastHour'=>3600] as $key=>$seconds) {
            $rate = $weights[$key] * self::SOLUTIONS_PER_WEIGHT / $seconds;
            if (!is_finite($rate)) throw new RuntimeException('Invalid rate');
            $result[$key] = ['seconds'=>$seconds,'difficultyWeight'=>$weights[$key],'estimatedSolps'=>$rate,
                'networkPercent'=>$network['status']==='ok' ? 100*$rate/$network['solps'] : null];
        }
        return $result;
    }

    public function snapshot(?string $address, int $now, ?array $node, ?array $mined, int $minimum): array
    {
        if ($address !== null) self::address($address);
        if ($minimum <= 0 || $minimum > ZclAmount::MAX) throw new RuntimeException('Invalid payout minimum');
        $coins = $this->rows('SELECT id FROM coins WHERE symbol=? LIMIT 2', ['ZCL']);
        if (count($coins) !== 1) throw new RuntimeException('Coin reporting unavailable');
        $coin = (int)$coins[0]['id'];
        $network = self::network($node, $now);
        $held = count($this->rows('SELECT coin_id FROM zcl_accounting_holds WHERE coin_id=? LIMIT 1', [$coin])) > 0;
        $result = ['schemaVersion'=>1,'asset'=>'ZCL','generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),
            'status'=>$held ? 'partial' : 'ok','address'=>$address,'network'=>$network,
            'pool'=>['mined'=>$mined,'work'=>$this->work($coin,null,$now,$network)],'miner'=>null,
            'accountingBasis'=>'retained-pool-ledger',
            'history'=>['basis'=>'session-observations-only','available'=>false]];
        if ($address === null) return $result;
        // Case-sensitive equality matters for Base58. PDO binds input; no interpolation.
        $comparison = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BINARY username=BINARY ?' : 'username=? COLLATE BINARY';
        $accounts = $this->rows('SELECT id,balance,payout_threshold,is_locked FROM accounts WHERE coinid=? AND '.$comparison.' LIMIT 2', [$coin,$address]);
        if (count($accounts) > 1) throw new RuntimeException('Ambiguous account');
        $account = $accounts[0] ?? null;
        $available = $account ? self::amount($account['balance']) : 0;
        $threshold = max($minimum, $account && $account['payout_threshold'] !== null ? self::amount($account['payout_threshold']) : 0);
        $amounts = ['earnedZat'=>0,'creditedZat'=>0,'immatureZat'=>0,'awaitingCreditZat'=>0,'paidZat'=>0,'pendingPayoutZat'=>0];
        $unknown = false;
        if ($account) {
            $id = (int)$account['id'];
            $earnings = $this->rows('SELECT E.amount,E.status,B.category,B.confirmations,R.blockhash AS round_hash,B.blockhash
                FROM earnings E LEFT JOIN blocks B ON B.id=E.blockid AND B.coin_id=E.coinid
                LEFT JOIN zcl_reward_rounds R ON R.block_id=B.id AND R.coin_id=B.coin_id
                WHERE E.userid=? AND E.coinid=? AND E.status IN (0,1,2) LIMIT '.(self::MAX_ROWS+1), [$id,$coin]);
            foreach ($earnings as $earning) {
                $value = self::amount($earning['amount']);
                $state = (int)$earning['status'];
                $consistent = in_array($earning['category'], ['generate','immature'], true)
                    && is_string($earning['round_hash']) && $earning['round_hash'] === $earning['blockhash'];
                if ($state >= 1 && ($earning['category'] !== 'generate' || (int)$earning['confirmations'] < 101)) $consistent = false;
                if ($state === 0 && ($earning['category'] !== 'immature' || (int)$earning['confirmations'] < 1)) $consistent = false;
                if (!$consistent) $unknown = true;
                if ($state !== 2 && !$consistent) continue;
                // Status 2 is durable historical credit; holds expose later reorg disputes.
                $key = $state === 2 ? 'creditedZat' : ($state === 1 ? 'awaitingCreditZat' : 'immatureZat');
                $amounts[$key] = self::add($amounts[$key], $value);
                $amounts['earnedZat'] = self::add($amounts['earnedZat'], $value);
            }
            $missingItems = $this->rows('SELECT P.id FROM payouts P LEFT JOIN zcl_payment_items I ON I.payout_id=P.id AND I.account_id=P.account_id
                WHERE P.account_id=? AND P.idcoin=? AND I.payout_id IS NULL LIMIT 1',[$id,$coin]);
            $missingPayments = $this->rows("SELECT I.payout_id FROM zcl_payment_items I
                LEFT JOIN zcl_payment_batches B ON B.id=I.batch_id AND B.coin_id=? AND B.purpose='miners'
                LEFT JOIN payouts P ON P.id=I.payout_id AND P.account_id=I.account_id AND P.idcoin=?
                WHERE I.account_id=? AND (B.id IS NULL OR P.id IS NULL) LIMIT 1",[$coin,$coin,$id]);
            if ($missingItems || $missingPayments) throw new RuntimeException('Incomplete payment journal');
            $items = $this->rows("SELECT I.amount_zat,I.address,P.amount,P.completed,P.tx,B.state,
                (SELECT COUNT(*) FROM zcl_payment_operations O WHERE O.batch_id=B.id AND O.kind='send' AND O.state='confirmed') AS confirmed_sends,
                (SELECT MAX(O.txid) FROM zcl_payment_operations O WHERE O.batch_id=B.id AND O.kind='send' AND O.state='confirmed') AS confirmed_tx
                FROM zcl_payment_items I
                JOIN zcl_payment_batches B ON B.id=I.batch_id AND B.coin_id=? AND B.purpose='miners'
                JOIN payouts P ON P.id=I.payout_id AND P.account_id=I.account_id AND P.idcoin=B.coin_id
                WHERE I.account_id=? LIMIT ".(self::MAX_ROWS+1), [$coin,$id]);
            foreach ($items as $item) {
                $value = self::zat($item['amount_zat']);
                if ($value !== self::amount($item['amount'])) throw new RuntimeException('Payment evidence mismatch');
                if ($item['address'] !== $address || !in_array($item['state'],['funding','operation','confirming','held','complete'],true)
                    || !in_array($item['completed'],[0,1,'0','1'],true)) throw new RuntimeException('Unsupported payment state');
                $completed = (int)$item['completed'] === 1;
                if ($completed !== ($item['state'] === 'complete')) throw new RuntimeException('Payment confirmation mismatch');
                if ($completed && ((int)$item['confirmed_sends'] !== 1 || !is_string($item['confirmed_tx'])
                    || !preg_match('/^[0-9a-f]{64}$/D',$item['confirmed_tx']) || $item['tx'] !== $item['confirmed_tx'])) {
                    throw new RuntimeException('Confirmed send evidence unavailable');
                }
                $key = $completed ? 'paidZat' : 'pendingPayoutZat';
                $amounts[$key] = self::add($amounts[$key],$value);
                if ($item['state'] === 'held') $unknown = true;
            }
        }
        $miner = ['status'=>$account ? ($held || $unknown ? 'partial' : 'ok') : 'not-found'];
        foreach ($amounts as $key=>$value) $miner[$key] = (string)$value;
        $miner += ['availableZat'=>(string)$available,'thresholdZat'=>(string)$threshold,
            'remainingZat'=>(string)max(0,$threshold-$available),'progressPercent'=>min(100,100*$available/$threshold),
            'thresholdReached'=>$available >= $threshold,'accountingHeld'=>$held,
            'payoutLocked'=>$account ? (bool)$account['is_locked'] : false,
            'work'=>$this->work($coin,$account ? (int)$account['id'] : -1,$now,$network)];
        $result['miner'] = $miner;
        if ($unknown) $result['status'] = 'partial';
        return $result;
    }
}
