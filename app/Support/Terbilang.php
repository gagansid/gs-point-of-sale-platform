<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nominal rupiah → kata Bahasa Indonesia untuk kuitansi ("Tiga ratus sembilan puluh tujuh ribu rupiah").
 * Hanya bagian bulat (tagihan langganan dalam rupiah penuh); sampai ratusan triliun.
 */
final class Terbilang
{
    private const ONES = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function rupiah(string $amount): string
    {
        $value = (int) explode('.', $amount)[0];

        return ucfirst($value === 0 ? 'nol' : self::words($value)).' rupiah';
    }

    private static function words(int $n): string
    {
        return trim(match (true) {
            $n < 12 => self::ONES[$n],
            $n < 20 => self::words($n - 10).' belas',
            $n < 100 => self::words(intdiv($n, 10)).' puluh '.self::words($n % 10),
            $n < 200 => 'seratus '.self::words($n - 100),
            $n < 1000 => self::words(intdiv($n, 100)).' ratus '.self::words($n % 100),
            $n < 2000 => 'seribu '.self::words($n - 1000),
            $n < 1_000_000 => self::words(intdiv($n, 1000)).' ribu '.self::words($n % 1000),
            $n < 1_000_000_000 => self::words(intdiv($n, 1_000_000)).' juta '.self::words($n % 1_000_000),
            $n < 1_000_000_000_000 => self::words(intdiv($n, 1_000_000_000)).' miliar '.self::words($n % 1_000_000_000),
            default => self::words(intdiv($n, 1_000_000_000_000)).' triliun '.self::words($n % 1_000_000_000_000),
        });
    }
}
