<?php

namespace App\Support;

final class Money
{
    public static function around(mixed $amount, ?string $currency = 'INR'): ?string
    {
        $formatted = self::format($amount, $currency);

        return $formatted === null ? null : 'Around '.$formatted;
    }

    public static function format(mixed $amount, ?string $currency = 'INR'): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $value = (float) $amount;

        if ($value <= 0) {
            return null;
        }
        $currency = strtoupper((string) ($currency ?: 'INR'));

        if ($currency === 'INR') {
            $formatted = fmod($value, 1.0) === 0.0
                ? number_format($value, 0)
                : number_format($value, 2);

            return '₹'.$formatted;
        }

        return $currency.' '.number_format($value, 2);
    }
}
