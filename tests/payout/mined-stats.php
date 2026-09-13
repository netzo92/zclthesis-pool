<?php
/** Synthetic evidence only: no database, network, wallet or mining access. */
require __DIR__.'/../../yiimp2/services/ZclRewardLedger.php';
require __DIR__.'/../../yiimp2/services/ZclMinedStats.php';

use app\services\ZclMinedStats;

$checks = 0;
function same($expected, $actual, string $label): void {
    global $checks;
    ++$checks;
    if ($expected !== $actual) throw new RuntimeException($label.': '.json_encode([$expected, $actual]));
}
function row(int $id, int $time, string $category = 'generate'): array {
    $hash = str_pad(dechex($id), 64, '0', STR_PAD_LEFT);
    return ['id'=>(string)$id, 'blockhash'=>$hash, 'height'=>1000+$id, 'time'=>(string)$time,
        'category'=>$category, 'amount'=>'1.23450001', 'round_block_id'=>(string)$id,
        'round_hash'=>$hash, 'reward_sat'=>'123450001', 'credited_sat'=>'122462401', 'retained_sat'=>'987600'];
}
$now = 2000000000;
$calls = [];
$header = static function(string $hash) use (&$calls): array {
    $calls[] = $hash;
    $id = hexdec(substr($hash, -4));
    return ['hash'=>$hash, 'height'=>1000+$id, 'confirmations'=>$id === 6 ? -1 : 150];
};
$empty = ZclMinedStats::summarize([], $header, $now);
same('ok', $empty['status'], 'a successfully read empty ledger is an observed zero');
same('0', $empty['allTime']['rewardZat'], 'empty reward');
same([], $calls, 'empty ledger does not invent RPC candidates');
same(null, $empty['coverageStartedAt'], 'no invented pool start time');

$rows = [row(1,$now-86401), row(2,$now-86400), row(3,$now-3600),
    row(4,$now-3599,'immature'), row(5,$now), row(6,$now-30,'orphan')];
$summary = ZclMinedStats::summarize($rows, $header, $now);
same('ok', $summary['status'], 'all selected records resolved');
same(5, $summary['allTime']['blocks'], 'all time accepted count');
same('617250005', $summary['allTime']['rewardZat'], 'integer sums retain last zatoshi');
same(3, $summary['last24h']['blocks'], '24-hour lower bound excluded');
same(2, $summary['lastHour']['blocks'], 'hour lower bound excluded, current second included');
same(1, $summary['lastHour']['immatureBlocks'], 'immature separated');
same('123450001', $summary['lastHour']['matureRewardZat'], 'mature amount');
same(1, $summary['excludedOrphans'], 'orphan excluded even with retained round');

$lowConfirmations = ZclMinedStats::summarize([row(1,$now-1)], static function($hash) use ($header) {
    return array_replace($header($hash), ['confirmations'=>100]);
}, $now);
same(1, $lowConfirmations['allTime']['immatureBlocks'], '100 confirmations remain immature');
same(0, $lowConfirmations['allTime']['matureBlocks'], 'maturity requires 101 confirmations');
$mature = ZclMinedStats::summarize([row(1,$now-1)], static function($hash) use ($header) {
    return array_replace($header($hash), ['confirmations'=>101]);
}, $now);
same(1, $mature['allTime']['matureBlocks'], '101 confirmation boundary');

$reorg = ZclMinedStats::summarize([row(1,$now-1)], static function($hash) use ($header) {
    return array_replace($header($hash), ['confirmations'=>-1]);
}, $now);
same('0', $reorg['allTime']['rewardZat'], 'canonical check excludes a newly orphaned mature round');
same(1, $reorg['excludedOrphans'], 'reorg excluded count');
same('ok', $reorg['status'], 'known orphan is not unknown');

foreach (['orphan','rejected'] as $category) {
    foreach ([-1,0,150] as $confirmations) {
        $checked = ZclMinedStats::summarize([row(1,$now-86401,$category)], static function($hash) use ($header,$confirmations) {
            return array_replace($header($hash), ['confirmations'=>$confirmations]);
        }, $now);
        same($confirmations < 0 ? 'ok' : 'partial', $checked['status'], 'live header checks old excluded category');
        same($confirmations < 0 ? 1 : 0, $checked['excludedOrphans'], 'only a node-verified orphan is excluded');
        same($confirmations < 0 ? 0 : 1, $checked['unknownBlocks'], 'restored canonical block requires reconciliation');
        same(0, $checked['allTime']['blocks'], 'disputed old category not automatically counted');
    }
    $missingHeader = ZclMinedStats::summarize([row(1,$now-10,$category)], static function($hash) {
        throw new RuntimeException('fixture header unavailable');
    }, $now);
    same('partial', $missingHeader['status'], 'excluded category with unavailable header is partial');
    same(1, $missingHeader['unknownBlocks'], 'unverified orphan is unknown');
    same(0, $missingHeader['excludedOrphans'], 'unverified orphan cannot be counted as excluded');
    $deadlineCalled = false;
    $deadlineOrphan = ZclMinedStats::summarize([row(1,$now-10,$category)], static function($hash) use (&$deadlineCalled,$header) {
        $deadlineCalled = true;
        return $header($hash);
    }, $now, false, 0, microtime(true)-1);
    same(false, $deadlineCalled, 'excluded category respects read deadline');
    same(1, $deadlineOrphan['unknownBlocks'], 'unchecked excluded category remains unknown');
}

foreach ([
    ['category'=>'new'], ['reward_sat'=>null], ['reward_sat'=>'1e8'], ['credited_sat'=>'0'],
    ['round_hash'=>str_repeat('f',64)], ['amount'=>'1.23450002'], ['time'=>(string)($now+1)],
    ['time'=>'0'], ['blockhash'=>'invalid'], ['reward_sat'=>'-100'], ['reward_sat'=>123450001.0],
] as $invalid) {
    $bad = ZclMinedStats::summarize([array_replace(row(1,$now-10), $invalid)], $header, $now);
    same('partial', $bad['status'], 'unresolved record is partial');
    same(1, $bad['unknownBlocks'], 'unresolved count');
    same('0', $bad['allTime']['rewardZat'], 'unresolved rewards never fabricated');
}
foreach ([['confirmations'=>0], ['confirmations'=>'150'], ['height'=>999], ['hash'=>str_repeat('e',64)]] as $invalid) {
    $bad = ZclMinedStats::summarize([row(1,$now-10)], static fn($hash) => array_replace($header($hash),$invalid), $now);
    same('partial', $bad['status'], 'invalid canonical evidence is unknown');
    same(0, $bad['allTime']['blocks'], 'invalid canonical evidence excluded');
}
$rpcFailure = ZclMinedStats::summarize([row(1,$now-10)], static function($hash) {
    throw new RuntimeException('fixture unavailable');
}, $now);
same('partial', $rpcFailure['status'], 'RPC failures are not observed zero');
same(1, $rpcFailure['unknownBlocks'], 'RPC failed block stays unknown');

$duplicate = row(1,$now-10);
$duplicate['id'] = '200';
$duplicate['round_block_id'] = null;
$duplicate['round_hash'] = null;
$duplicate['category'] = 'new';
$deduped = ZclMinedStats::summarize([row(1,$now-10),$duplicate], $header, $now);
same(1, $deduped['allTime']['blocks'], 'duplicate hash counts the unique journaled block once');
same('ok', $deduped['status'], 'transient duplicate cannot double count');

$held = ZclMinedStats::summarize([], $header, $now, true, 2);
same('partial', $held['status'], 'accounting hold visible');
same(true, $held['accountingHeld'], 'hold flag');
same(2, $held['unknownBlocks'], 'journal rows missing blocks do not disappear silently');
$timeout = ZclMinedStats::summarize([row(1,$now-10)], static function($hash) {
    throw new RuntimeException('must not call after deadline');
}, $now, false, 0, microtime(true)-1);
same('partial', $timeout['status'], 'bounded check never calls unverified block accepted');
same(1, $timeout['unknownBlocks'], 'unchecked candidate retained as unknown');
$overflow = ZclMinedStats::summarize(array_fill(0,ZclMinedStats::MAX_ROWS+1,row(1,$now-10)), $header, $now);
same('unavailable', $overflow['status'], 'row limit never silently truncates all time');
same(null, $overflow['allTime'], 'overflow never yields fake zero');
same(null, ZclMinedStats::unavailable($now)['lastHour'], 'unavailable windows remain null');
echo "Pool mined statistics: {$checks} checks passed.\n";
