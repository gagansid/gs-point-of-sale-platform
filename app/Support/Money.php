<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmatika uang presisi (bcmath, skala 2). Uang selalu string desimal "62000.00";
 * dilarang float untuk nominal (docs/standards/coding.md §4).
 */
final class Money
{
    private const SCALE = 2;

    /** Menormalkan input (int/string desimal) menjadi "62000.00". */
    public static function of(int|string $value): string
    {
        $value = is_int($value) ? (string) $value : trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Nominal tidak valid: {$value}");
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn (string $sum, string $v): string => bcadd($sum, $v, self::SCALE), '0.00');
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, int|string $b): string
    {
        return bcmul($a, (string) $b, self::SCALE);
    }

    /** -1, 0, atau 1. */
    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /** Format tampilan Indonesia: Rp62.000 / Rp1.250.500,50 / -Rp5.000. */
    public static function format(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$int, $dec] = explode('.', ltrim(self::of($value), '-'));
        // Pengelompokan ribuan manual (tanpa float)
        $grouped = strrev(implode('.', str_split(strrev($int), 3)));
        $text = 'Rp'.$grouped.($dec !== '00' ? ','.$dec : '');

        return $negative ? '-'.$text : $text;
    }
}
