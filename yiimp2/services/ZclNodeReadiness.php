<?php
namespace app\services;

/** Mainnet launch/worker guard. Bootstrap hold is an active finalization gate. */
final class ZclNodeReadiness
{
    /** A present live diagnostic is authoritative only for this exact tip. */
    public static function finalizationReady(array $chain): bool
    {
        if (!array_key_exists('live_corroboration', $chain)) {
            // Older daemons keep the original fail-closed cached-hold contract.
            return ($chain['finalization_hold']['held'] ?? true) === false;
        }
        $live = $chain['live_corroboration'];
        return is_array($live)
            && ($live['schemaVersion'] ?? null) === 1
            && ($live['ready'] ?? null) === true
            && is_string($live['tipHash'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $live['tipHash']) === 1
            && $live['tipHash'] === ($chain['bestblockhash'] ?? null)
            && is_int($live['tipHeight'] ?? null) && $live['tipHeight'] >= 0
            && $live['tipHeight'] === ($chain['blocks'] ?? null)
            && is_int($live['requiredDepth'] ?? null) && $live['requiredDepth'] > 0
            && $live['requiredDepth'] <= $live['tipHeight']
            && is_int($live['candidateHeight'] ?? null)
            && $live['candidateHeight'] === $live['tipHeight'] - $live['requiredDepth']
            && is_int($live['requiredPeers'] ?? null)
            && $live['requiredPeers'] >= (($chain['chain'] ?? '') === 'main' ? 2 : 1)
            && is_string($live['reason'] ?? null);
    }

    public static function ready(array $chain, array $header, int $connections, int $now): bool
    {
        return ($chain['chain'] ?? '') === 'main'
            && ($chain['initialblockdownload'] ?? false) === false
            && is_int($chain['blocks'] ?? null) && $chain['blocks'] > 0
            && ($chain['headers'] ?? null) === $chain['blocks']
            && is_numeric($chain['verificationprogress'] ?? null) && $chain['verificationprogress'] >= 0.9999
            && ($chain['bootstrap_validation']['tip_hold'] ?? true) === false
            && self::finalizationReady($chain)
            && is_string($chain['bestblockhash'] ?? null) && preg_match('/^[0-9a-f]{64}$/D',$chain['bestblockhash']) === 1
            && ($header['hash'] ?? null) === $chain['bestblockhash']
            && ($header['height'] ?? null) === $chain['blocks']
            && ($header['confirmations'] ?? 0) >= 1
            && is_int($header['time'] ?? null) && $header['time'] <= $now + 600 && $header['time'] >= $now - 1800
            && $connections >= 1;
    }
}
