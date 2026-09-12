<?php
namespace app\services;

/** Integer zatoshis internally; fixed-point decimal strings at database/RPC boundaries. */
final class ZclAmount
{
    public const SCALE = 100000000;
    public const MAX = 21000000 * self::SCALE;

    public static function parse($value): int
    {
        if (is_float($value)) {
            if (!is_finite($value)) throw new \InvalidArgumentException('Invalid coin amount');
            $value = number_format($value, 8, '.', '');
        }
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,8}))?$/D', $value, $m)) {
            throw new \InvalidArgumentException('Coin amount must be a nonnegative decimal with at most eight places');
        }
        $amount = (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '', 8, '0');
        if ($amount > self::MAX) throw new \InvalidArgumentException('Coin amount exceeds ZCL supply bound');
        return $amount;
    }

    public static function decimal(int $amount): string
    {
        if ($amount < 0 || $amount > self::MAX) throw new \InvalidArgumentException('Invalid zatoshi amount');
        return intdiv($amount, self::SCALE) . '.' . str_pad((string) ($amount % self::SCALE), 8, '0', STR_PAD_LEFT);
    }
}
