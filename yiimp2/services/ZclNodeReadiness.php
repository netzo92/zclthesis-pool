<?php
namespace app\services;

/** Mainnet launch/worker guard. Bootstrap hold is an active finalization gate. */
final class ZclNodeReadiness
{
    public static function ready(array $chain, array $header, int $connections, int $now): bool
    {
        return ($chain['chain'] ?? '') === 'main'
            && ($chain['initialblockdownload'] ?? false) === false
            && is_int($chain['blocks'] ?? null) && $chain['blocks'] > 0
            && ($chain['headers'] ?? null) === $chain['blocks']
            && is_numeric($chain['verificationprogress'] ?? null) && $chain['verificationprogress'] >= 0.9999
            && ($chain['bootstrap_validation']['tip_hold'] ?? true) === false
            && ($chain['finalization_hold']['held'] ?? true) === false
            && is_string($chain['bestblockhash'] ?? null) && preg_match('/^[0-9a-f]{64}$/D',$chain['bestblockhash']) === 1
            && ($header['hash'] ?? null) === $chain['bestblockhash']
            && ($header['height'] ?? null) === $chain['blocks']
            && ($header['confirmations'] ?? 0) >= 1
            && is_int($header['time'] ?? null) && $header['time'] <= $now + 600 && $header['time'] >= $now - 1800
            && $connections >= 1;
    }
}
