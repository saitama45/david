<?php

namespace App\Support;

final class StockQuantity
{
    private const SCALE = 10;
    private const ZERO_THRESHOLD = 0.00000000005;

    public static function normalize(int|float|string|null $quantity): string
    {
        $value = round((float) ($quantity ?? 0), self::SCALE);

        if (abs($value) < self::ZERO_THRESHOLD) {
            $value = 0.0;
        }

        return number_format($value, self::SCALE, '.', '');
    }

    public static function adjustment(int|float|string|null $finalQuantity, int|float|string|null $currentQuantity): string
    {
        return self::normalize((float) ($finalQuantity ?? 0) - (float) ($currentQuantity ?? 0));
    }

    public static function absolute(int|float|string|null $quantity): string
    {
        return self::normalize(abs((float) ($quantity ?? 0)));
    }

    public static function isZero(int|float|string|null $quantity): bool
    {
        return self::normalize($quantity) === self::normalize(0);
    }

    public static function isPositive(int|float|string|null $quantity): bool
    {
        return (float) self::normalize($quantity) > 0;
    }
}
