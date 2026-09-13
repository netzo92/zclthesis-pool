<?php
namespace app\services;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/ZclRewardLedger.php';

/** A read-only conditional allocation of the next subsidy, never earned money. */
final class ZclRoundProjection
{
    public const MAX_ROWS = 10000;
    private const SCALE = '1000000000000000000000000';
    private const MAX_ZAT = '2100000000000000';

    public function __construct(private PDO $db) {}

    private function rows(string $sql, array $params = []): array
    {
        $query = $this->db->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) throw new RuntimeException('row-limit');
        return $rows;
    }

    private static function integer($value, int $minimum = 0): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]{0,18})$/D',(string)$value)
            || bccomp((string)$value,(string)PHP_INT_MAX,0)>0 || (int)$value<$minimum) {
            throw new RuntimeException('inconsistent-ledger');
        }
        return (int)$value;
    }

    private static function percent($value): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^\d{1,3}(?:\.\d{1,8})?$/D',(string)$value)
            || bccomp((string)$value,'100',8)>0) throw new RuntimeException('invalid-fee-configuration');
        return (string)$value;
    }

    private static function deduction(string $amount, string $percent): string
    {
        // Identical to ZclRewardLedger::afterFee: floor the deduction, not the net.
        return bcdiv(bcmul($amount,$percent,8),'100',0);
    }

    private static function instant($value): float
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$value)) {
            throw new RuntimeException('invalid-node-context');
        }
        $date = new \DateTimeImmutable($value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new RuntimeException('invalid-node-context');
        return (float)$date->format('U.u');
    }

    private static function context(?array $node, int $now): array
    {
        $subsidy = $node['mining']['nextBlockSubsidy'] ?? null;
        if (($node['schemaVersion'] ?? null)!==1 || ($node['asset'] ?? null)!=='ZCL'
            || ($node['source'] ?? null)!=='https://pool.zclthesis.com'
            || !is_array($subsidy) || ($subsidy['schemaVersion'] ?? null)!==1 || ($subsidy['asset'] ?? null)!=='ZCL'
            || ($subsidy['status'] ?? null)!=='ok' || ($subsidy['basis'] ?? null)!=='next-height-subsidy-excluding-transaction-fees') {
            throw new RuntimeException('invalid-node-context');
        }
        if (($node['node']['synced'] ?? null)!==true) throw new RuntimeException('node-not-current');
        $observed = self::instant($node['generatedAt'] ?? null);
        $source = self::instant($subsidy['generatedAt'] ?? null);
        if ($source!==$observed || $source>$now+1 || $source<$now-180) throw new RuntimeException('stale-node-context');
        $blockAt = self::instant($node['chain']['blockAt'] ?? null);
        if ($blockAt<$now-1800 || $blockAt>$now+300) throw new RuntimeException('node-not-current');
        $height = $node['chain']['height'] ?? null;
        $hash = $node['chain']['hash'] ?? null;
        if (!is_int($height) || $height<0 || $height>=2147483647 || !is_string($hash)
            || !preg_match('/^[0-9a-f]{64}$/D',$hash) || ($subsidy['tipHeight'] ?? null)!==$height
            || ($subsidy['tipHash'] ?? null)!==$hash || ($subsidy['height'] ?? null)!==$height+1
            || !is_string($subsidy['subsidyZat'] ?? null) || !preg_match('/^[1-9][0-9]{0,15}$/D',$subsidy['subsidyZat'])
            || bccomp($subsidy['subsidyZat'],self::MAX_ZAT,0)>0) throw new RuntimeException('invalid-node-context');
        return ['sourceGeneratedAt'=>$subsidy['generatedAt'],'tipHeight'=>$height,'tipHash'=>$hash,
            'height'=>$height+1,'subsidyZat'=>$subsidy['subsidyZat']];
    }

    private static function empty(string $address, int $now): array
    {
        return ['schemaVersion'=>1,'asset'=>'ZCL','address'=>$address,'status'=>'unavailable','reason'=>'inconsistent-ledger',
            'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'basis'=>'conditional-next-block-subsidy',
            'workBasis'=>'retained-unconsumed-round-shares','subsidyBasis'=>'next-height-subsidy-excluding-transaction-fees',
            'sourceGeneratedAt'=>null,'tipHeight'=>null,'tipHash'=>null,'height'=>null,'roundId'=>null,'subsidyZat'=>null,
            'feePercent'=>null,'poolWeight'=>null,'addressWeight'=>null,'sharePercent'=>null,
            'grossZat'=>null,'poolFeeZat'=>null,'additionalDonationZat'=>null,'allocationZat'=>null];
    }

    /** Caller supplies the same read-only repeatable-read snapshot as miner accounting. */
    public function snapshot(int $coin, string $address, ?int $account, int $now, ?array $node, bool $held, bool $consistent): array
    {
        $result = self::empty($address,$now);
        try {
            if (!extension_loaded('bcmath')) throw new RuntimeException('arithmetic-unavailable');
            if ($held) throw new RuntimeException('accounting-held');
            if (!$consistent) throw new RuntimeException('inconsistent-ledger');
            if (!$this->db->inTransaction()) throw new RuntimeException('snapshot-unavailable');
            $context = self::context($node,$now);
            // The approved launch fee is fixed. A future fee change must update
            // this contract and clear BlockService's cached fee before estimates resume.
            $fee = self::percent(defined('YIIMP_FEES_MINING') ? YIIMP_FEES_MINING : '0.8');
            if (bccomp($fee,'0.8',8)!==0) throw new RuntimeException('invalid-fee-configuration');
            $fee = '0.8';
            $coins = $this->rows('SELECT symbol,algo,auto_exchange FROM coins WHERE id=? LIMIT 2',[$coin]);
            if (count($coins)!==1 || $coins[0]['symbol']!=='ZCL' || $coins[0]['algo']!=='equihash192'
                || !in_array($coins[0]['auto_exchange'],[0,'0',null],true)) throw new RuntimeException('inconsistent-ledger');
            if ($this->rows('SELECT coin_id FROM zcl_accounting_holds WHERE coin_id=? LIMIT 1',[$coin])) throw new RuntimeException('accounting-held');
            // Do not give the same work to a hypothetical round while a real pool
            // block is still awaiting allocation (including a just-found block).
            if ($this->rows("SELECT B.id FROM blocks B WHERE B.coin_id=? AND B.category IN ('new','immature','generate')
                AND NOT EXISTS (SELECT 1 FROM zcl_reward_rounds R WHERE R.coin_id=B.coin_id AND R.blockhash=B.blockhash)
                LIMIT 1",[$coin])) throw new RuntimeException('pending-pool-block');
            $rounds = $this->rows('SELECT R.block_id,R.blockhash,R.created_at,B.blockhash AS source_hash,B.height,B.time,B.category
                FROM zcl_reward_rounds R LEFT JOIN blocks B ON B.id=R.block_id AND B.coin_id=R.coin_id
                WHERE R.coin_id=? ORDER BY R.created_at DESC,R.block_id DESC LIMIT '.(self::MAX_ROWS+1),[$coin]);
            $roundId = 'initial';
            foreach ($rounds as $index=>$round) {
                if (!is_string($round['blockhash']) || !preg_match('/^[0-9a-f]{64}$/D',$round['blockhash'])
                    || $round['source_hash']!==$round['blockhash'] || self::integer($round['created_at'],1)>$now
                    || self::integer($round['height'],1)>$context['tipHeight'] || self::integer($round['time'],1)>$now
                    || !in_array($round['category'],['immature','generate','orphan'],true)) throw new RuntimeException('inconsistent-ledger');
                if ($index===0) $roundId = $round['blockhash'];
            }
            // Match the allocator's exact DECIMAL conversion, without a last-hour
            // cutoff. SQLite fixtures store that conversion as exact decimal TEXT.
            $weight = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'S.difficulty' : 'CAST(S.difficulty AS DECIMAL(50,24))';
            $shares = $this->rows("SELECT S.id,S.userid,S.time,S.blocknumber,{$weight} AS weight,
                A.id AS account_id,A.coinid AS account_coin,A.username,A.no_fees,A.donation
                FROM shares S LEFT JOIN accounts A ON A.id=S.userid
                WHERE S.coinid=? AND S.algo=? AND S.valid=1 AND S.blockrewarded IS NULL AND COALESCE(S.solo,0)=0
                ORDER BY S.id LIMIT ".(self::MAX_ROWS+1),[$coin,'equihash192']);
            if (!$shares) throw new RuntimeException('no-round-work');
            $weights = $accounts = [];
            $lastId = 0;
            foreach ($shares as $share) {
                $id = self::integer($share['id'],1);
                if ($id<=$lastId) throw new RuntimeException('inconsistent-ledger');
                $lastId = $id;
                if (self::integer($share['time'],1)>$now || self::integer($share['blocknumber'],1)>$context['height']) {
                    throw new RuntimeException('future-round-work');
                }
                $user = self::integer($share['userid'],1);
                if (self::integer($share['account_id'],1)!==$user || self::integer($share['account_coin'],1)!==$coin
                    || !in_array($share['no_fees'],[0,1,'0','1',null],true)) throw new RuntimeException('inconsistent-ledger');
                $donation = self::percent($share['donation'] ?? '0');
                if (($user===$account && $share['username']!==$address) || ($share['username']===$address && $user!==$account)) {
                    throw new RuntimeException('inconsistent-ledger');
                }
                if ((!is_string($share['weight']) && !is_int($share['weight']))
                    || !preg_match('/^\d{1,26}(?:\.\d{1,24})?$/D',(string)$share['weight'])) throw new RuntimeException('invalid-round-work');
                $scaled = bcmul((string)$share['weight'],self::SCALE,0);
                if (bccomp($scaled,'0',0)<=0) throw new RuntimeException('invalid-round-work');
                $weights[$user] = bcadd($weights[$user] ?? '0',$scaled,0);
                $accounts[$user] = ['no_fees'=>(bool)$share['no_fees'],'donation'=>$donation];
            }
            $gross = ZclRewardLedger::split($context['subsidyZat'],$weights);
            $feeBase = '0'; $feeWeights = [];
            foreach ($gross as $user=>$amount) {
                if (!$accounts[$user]['no_fees'] && bccomp($amount,'0',0)>0) {
                    $feeWeights[$user] = $amount;
                    $feeBase = bcadd($feeBase,$amount,0);
                }
            }
            $fees = $feeWeights ? ZclRewardLedger::split(self::deduction($feeBase,$fee),$feeWeights) : [];
            $ownGross = $gross[$account] ?? '0';
            $ownFee = $fees[$account] ?? '0';
            $net = bcsub($ownGross,$ownFee,0);
            $donation = self::deduction($net,$accounts[$account]['donation'] ?? '0');
            $total = array_reduce($weights,static fn($sum,$weight)=>bcadd($sum,$weight,0),'0');
            $ownWeight = $weights[$account] ?? '0';
            return array_replace($result,$context,['status'=>'ok','reason'=>null,'roundId'=>$roundId,'feePercent'=>$fee,
                'poolWeight'=>bcdiv($total,self::SCALE,24),'addressWeight'=>bcdiv($ownWeight,self::SCALE,24),
                'sharePercent'=>(float)bcdiv(bcmul($ownWeight,'100',0),$total,16),
                'grossZat'=>$ownGross,'poolFeeZat'=>$ownFee,'additionalDonationZat'=>$donation,'allocationZat'=>bcsub($net,$donation,0)]);
        } catch (Throwable $error) {
            // Never expose SQL, account IDs or diagnostic text in a public response.
            $reason = $error->getMessage();
            $allowed = ['row-limit','accounting-held','inconsistent-ledger','snapshot-unavailable','arithmetic-unavailable',
                'invalid-node-context','stale-node-context','node-not-current','invalid-fee-configuration',
                'pending-pool-block','no-round-work','future-round-work','invalid-round-work'];
            $result['reason'] = in_array($reason,$allowed,true) ? $reason : 'inconsistent-ledger';
            return $result;
        }
    }
}
