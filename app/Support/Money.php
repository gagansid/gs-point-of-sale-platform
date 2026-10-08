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

    /**
     * Normalisasi hasil agregat database (SUM). MySQL mengembalikan string desimal yang presisi;
     * SQLite (test) mengembalikan float — hanya di jalur ini float diubah ke string 2 desimal.
     */
    public static function fromDb(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '0.00',
            is_float($value) => self::of(sprintf('%.2f', $value)),
            default => self::of((string) $value),
        };
    }

    /** $a ÷ $b dibulatkan half-up 2 desimal; 0 bila pembagi 0. */
    public static function div(string $a, int|string $b): string
    {
        return bccomp((string) $b, '0', 2) === 0 ? '0.00' : self::round(bcdiv($a, (string) $b, 6));
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

    /** Pembulatan half-up ke $scale desimal (bcmath memotong, bukan membulatkan). */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $offset = str_starts_with($value, '-') ? '-'.$offset : $offset;

        return bcadd(bcadd($value, $offset, $scale + 1), '0', $scale);
    }

    /** $amount × $rate% dibulatkan half-up ke 2 desimal. */
    public static function percentOf(string $amount, string $rate): string
    {
        return self::round(bcdiv(bcmul($amount, $rate, 6), '100', 6));
    }

    /** Bagian pajak yang sudah termasuk di harga: $gross × rate / (100 + rate). */
    public static function includedTax(string $gross, string $rate): string
    {
        return self::round(bcdiv(bcmul($gross, $rate, 6), bcadd('100', $rate, 6), 6));
    }

    /** Membulatkan ke kelipatan $step terdekat (half-up), mis. 75757.50 → 75800 untuk step 100. */
    public static function roundToNearest(string $value, int $step): string
    {
        if ($step <= 0) {
            return self::of($value);
        }

        $multiplier = self::round(bcdiv($value, (string) $step, 6), 0);

        return bcmul($multiplier, (string) $step, self::SCALE);
    }

    public static function min(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? $a : $b;
    }

    public static function max(string $a, string $b): string
    {
        return self::compare($a, $b) >= 0 ? $a : $b;
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
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
