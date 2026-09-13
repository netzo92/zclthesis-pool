<?php
namespace app\services;

use PDO;
use RuntimeException;
use Throwable;

/** Aggregate public ledger observations. No wallet RPC, writes, or private config. */
final class ZclTreasuryStats
{
    public const RECIPIENT = 't1Q8PRCDso9HoK36XeLCPym6vZmkwyNgS4d';
    public const MAX_ROWS = 10000;

    public function __construct(private PDO $db) {}

    public static function unavailable(int $now): array
    {
        return ['schemaVersion'=>1,'asset'=>'ZCL','unit'=>'zatoshi','recipient'=>self::RECIPIENT,
            'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'status'=>'unavailable',
            'coverageBasis'=>'retained-pool-ledger','allocationBasis'=>'retained-ledger-block-status',
            'receivedBasis'=>'confirmed-payout-journal','coverageStartedAt'=>null,
            'canonicalStatus'=>'unavailable','accountingHeld'=>null,'unknownRounds'=>null,
            'unknownPayments'=>null,'excludedOrphanRounds'=>null,'allocated'=>null,'received'=>null];
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $query=$this->db->prepare($sql);
        $query->execute($parameters);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>self::MAX_ROWS) throw new RuntimeException('Treasury reporting limit reached');
        return $rows;
    }

    private function equal(string $column): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? 'BINARY '.$column.'=BINARY ?' : $column.'=? COLLATE BINARY';
    }

    private static function zat($value): int
    {
        if ((!is_int($value)&&!is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D',(string)$value)
            || (int)$value>ZclAmount::MAX) throw new RuntimeException('Invalid exact treasury amount');
        return (int)$value;
    }

    private static function coins($value): int
    {
        if (!is_int($value)&&!is_string($value)) throw new RuntimeException('Invalid exact coin amount');
        return ZclAmount::parse($value);
    }

    private static function add(int $a, int $b): int
    {
        if ($a<0 || $b<0 || $a>ZclAmount::MAX-$b) throw new RuntimeException('Treasury amount exceeds bound');
        return $a+$b;
    }

    private static function bucket(): array { return ['totalZat'=>'0','matureZat'=>'0','immatureZat'=>'0']; }

    private static function addAllocation(array &$bucket, int $amount, bool $mature): void
    {
        $key=$mature?'matureZat':'immatureZat';
        $total=self::add(self::zat($bucket['totalZat']),$amount);
        $component=self::add(self::zat($bucket[$key]),$amount);
        $bucket['totalZat']=(string)$total;
        $bucket[$key]=(string)$component;
    }

    private static function canonical(?array $mined, int $now): string
    {
        if (($mined['schemaVersion']??null)!==1 || ($mined['asset']??null)!=='ZCL'
            || !in_array($mined['status']??null,['ok','partial'],true)) return 'unavailable';
        $time=is_string($mined['generatedAt']??null)?strtotime($mined['generatedAt']):false;
        if ($time===false || $time>$now+60) return 'unavailable';
        if ($time<$now-180) return 'stale';
        return $mined['status'];
    }

    /** Called inside the endpoint's bounded, read-only consistent transaction. */
    public function snapshot(int $coin, int $now, bool $held, ?array $mined): array
    {
        try { return $this->read($coin,$now,$held,$mined); }
        catch (Throwable $error) { return self::unavailable($now); }
    }

    private function read(int $coin, int $now, bool $held, ?array $mined): array
    {
        $result=self::unavailable($now);
        $result['canonicalStatus']=self::canonical($mined,$now);
        $result['status']=$held || $result['canonicalStatus']!=='ok'?'partial':'ok';
        $result['accountingHeld']=$held;
        $result['unknownRounds']=$result['unknownPayments']=$result['excludedOrphanRounds']=0;
        $unknownRounds=[];
        $unknownRound=static function($id) use (&$unknownRounds): void { $unknownRounds[(string)$id]=true; };
        $result['allocated']=['operatorFees'=>self::bucket(),'otherRetained'=>self::bucket(),'sharedMining'=>self::bucket()];
        $result['received']=['operatorTransfersZat'=>'0','sharedMiningZat'=>'0','totalZat'=>'0'];
        $limit=' LIMIT '.(self::MAX_ROWS+1);
        $accounts=$this->rows('SELECT id FROM accounts WHERE coinid=? AND '.$this->equal('username').' LIMIT 2',[$coin,self::RECIPIENT]);
        if (count($accounts)>1) throw new RuntimeException('Ambiguous treasury account');
        $account=$accounts?(int)$accounts[0]['id']:null;
        $earnings=[];
        if ($account!==null) {
            foreach ($this->rows('SELECT blockid,amount,status FROM earnings WHERE coinid=? AND userid=?'.$limit,[$coin,$account]) as $earning) {
                $earnings[(string)$earning['blockid']][]=$earning;
            }
        }
        $blocks=$this->rows('SELECT B.id,B.blockhash,B.category,B.confirmations,B.amount,
            R.block_id AS round_id,R.blockhash AS round_hash,R.reward_sat,R.credited_sat,R.retained_sat,R.fee_sat
            FROM blocks B LEFT JOIN zcl_reward_rounds R ON R.block_id=B.id AND R.coin_id=B.coin_id
            WHERE B.coin_id=?'.$limit,[$coin]);
        $groups=[];
        foreach ($blocks as $block) {
            if (!is_string($block['blockhash']) || !preg_match('/^[0-9a-f]{64}$/D',$block['blockhash'])) {
                $unknownRound($block['id']); continue;
            }
            $groups[$block['blockhash']][]=$block;
        }
        $seenBlocks=[];
        foreach ($groups as $hash=>$duplicates) {
            $journaled=array_values(array_filter($duplicates,static fn($b)=>
                $b['round_hash']===$hash && (string)$b['round_id']===(string)$b['id']));
            if (count($journaled)===1) $block=$journaled[0];
            elseif (count($duplicates)===1) $block=$duplicates[0];
            else { foreach ($duplicates as $duplicate) $unknownRound($duplicate['id']); continue; }
            $id=(string)$block['id']; $seenBlocks[$id]=true;
            if (in_array($block['category'],['orphan','rejected'],true)) {
                ++$result['excludedOrphanRounds'];
                foreach ($earnings[$id]??[] as $earning) if ((string)$earning['status']==='2') $unknownRound($id);
                continue;
            }
            try {
                if (!in_array($block['category'],['generate','immature'],true) || $block['round_hash']!==$hash
                    || (string)$block['round_id']!==$id || !preg_match('/^[1-9][0-9]*$/D',(string)$block['confirmations'])) {
                    throw new RuntimeException('Unresolved source block');
                }
                $reward=self::zat($block['reward_sat']); $credited=self::zat($block['credited_sat']); $retained=self::zat($block['retained_sat']);
                if (!$reward || self::add($credited,$retained)!==$reward || self::coins($block['amount'])!==$reward) {
                    throw new RuntimeException('Source reward does not reconcile');
                }
                $mature=$block['category']==='generate' && (int)$block['confirmations']>=101;
            } catch (Throwable $error) { $unknownRound($id); continue; }
            try {
                $fee=self::zat($block['fee_sat']);
                if ($fee>$retained) throw new RuntimeException('Fee exceeds retained reward');
                self::addAllocation($result['allocated']['operatorFees'],$fee,$mature);
                self::addAllocation($result['allocated']['otherRetained'],$retained-$fee,$mature);
            } catch (Throwable $error) { $unknownRound($id); }
            if (isset($earnings[$id])) {
                try {
                    if (count($earnings[$id])!==1) throw new RuntimeException('Duplicate shared allocation');
                    $earning=$earnings[$id][0]; $amount=self::coins($earning['amount']);
                    if ($amount>$credited || !in_array((string)$earning['status'],$mature?['1','2']:['0'],true)) {
                        throw new RuntimeException('Shared allocation state disagrees');
                    }
                    self::addAllocation($result['allocated']['sharedMining'],$amount,$mature);
                } catch (Throwable $error) { $unknownRound($id); }
            }
        }
        foreach ($earnings as $id=>$unused) if (!isset($seenBlocks[$id])) $unknownRound($id);
        $missing=$this->rows('SELECT R.block_id FROM zcl_reward_rounds R LEFT JOIN blocks B ON B.id=R.block_id AND B.coin_id=R.coin_id
            WHERE R.coin_id=? AND B.id IS NULL'.$limit,[$coin]);
        foreach ($missing as $round) $unknownRound($round['block_id']);
        $result['unknownRounds']=count($unknownRounds);

        // Only journal-confirmed sends count as receipts. Shielding, accrual,
        // pending reservations and internal credits cannot increase this total.
        $confirmation="(SELECT COUNT(*) FROM zcl_payment_operations O WHERE O.batch_id=B.id AND O.kind='send' AND O.state='confirmed') AS confirmed_sends,
            (SELECT MAX(O.txid) FROM zcl_payment_operations O WHERE O.batch_id=B.id AND O.kind='send' AND O.state='confirmed') AS confirmed_tx";
        $candidates=[];
        foreach ($this->rows("SELECT B.id,B.state,B.amount_zat,$confirmation,
            (SELECT COUNT(*) FROM zcl_payment_items I WHERE I.batch_id=B.id) AS item_count
            FROM zcl_payment_batches B WHERE B.coin_id=? AND B.purpose='operator' AND ".$this->equal('B.operator_address').$limit,[$coin,self::RECIPIENT]) as $batch) {
            try {
                if (!in_array($batch['state'],['funding','operation','confirming','held','complete','cancelled'],true)
                    || (int)$batch['item_count']!==0) throw new RuntimeException('Invalid operator batch');
                if ($batch['state']==='held') throw new RuntimeException('Held operator outcome');
                if ($batch['state']!=='complete') {
                    if ((int)$batch['confirmed_sends']!==0) throw new RuntimeException('Unfinished confirmed transfer');
                    continue;
                }
                $amount=self::zat($batch['amount_zat']);
                if (!$amount || (int)$batch['confirmed_sends']!==1 || !is_string($batch['confirmed_tx'])
                    || !preg_match('/^[0-9a-f]{64}$/D',$batch['confirmed_tx'])) throw new RuntimeException('Missing confirmed operator send');
                $candidates[$batch['confirmed_tx']][]=['key'=>'operatorTransfersZat','amount'=>$amount];
            } catch (Throwable $error) { ++$result['unknownPayments']; }
        }
        if ($account!==null) {
            $missing=$this->rows('SELECT P.id FROM payouts P LEFT JOIN zcl_payment_items I ON I.payout_id=P.id AND I.account_id=P.account_id
                WHERE P.account_id=? AND P.idcoin=? AND (I.payout_id IS NULL OR NOT ('.$this->equal('I.address').'))'.$limit,[$account,$coin,self::RECIPIENT]);
            $result['unknownPayments']+=count($missing);
        }
        foreach ($this->rows("SELECT I.amount_zat,I.account_id,P.amount,P.completed,P.tx,B.id,B.state,B.purpose,$confirmation
            FROM zcl_payment_items I LEFT JOIN zcl_payment_batches B ON B.id=I.batch_id
            LEFT JOIN payouts P ON P.id=I.payout_id AND P.account_id=I.account_id AND P.idcoin=B.coin_id
            WHERE ".$this->equal('I.address')." AND (B.coin_id=? OR B.id IS NULL)".$limit,[self::RECIPIENT,$coin]) as $item) {
            try {
                $amount=self::zat($item['amount_zat']);
                if (!$amount || $amount!==self::coins($item['amount']) || $item['purpose']!=='miners'
                    || !in_array($item['state'],['funding','operation','confirming','held','complete'],true)
                    || !in_array($item['completed'],[0,1,'0','1'],true)) throw new RuntimeException('Invalid shared payout');
                if ($item['state']==='held') throw new RuntimeException('Held shared payout');
                $complete=(int)$item['completed']===1;
                if ($complete!==($item['state']==='complete')) throw new RuntimeException('Inconsistent shared completion');
                if (!$complete) {
                    if ((int)$item['confirmed_sends']!==0) throw new RuntimeException('Unfinished confirmed transfer');
                    continue;
                }
                if ((int)$item['confirmed_sends']!==1 || !is_string($item['confirmed_tx'])
                    || !preg_match('/^[0-9a-f]{64}$/D',$item['confirmed_tx']) || $item['tx']!==$item['confirmed_tx']) {
                    throw new RuntimeException('Missing confirmed shared send');
                }
                $candidates[$item['confirmed_tx']][]=['key'=>'sharedMiningZat','amount'=>$amount];
            } catch (Throwable $error) { ++$result['unknownPayments']; }
        }
        foreach ($candidates as $transfers) {
            if (count($transfers)!==1) { $result['unknownPayments']+=count($transfers); continue; }
            $transfer=$transfers[0]; $key=$transfer['key'];
            $result['received'][$key]=(string)self::add(self::zat($result['received'][$key]),$transfer['amount']);
        }
        $result['received']['totalZat']=(string)self::add(self::zat($result['received']['operatorTransfersZat']),self::zat($result['received']['sharedMiningZat']));
        if ($result['unknownRounds'] || $result['unknownPayments']) $result['status']='partial';
        return $result;
    }
}
