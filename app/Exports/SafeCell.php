<?php

declare(strict_types=1);

namespace App\Exports;

/**
 * Mencegah formula injection (CSV/Excel injection): teks dari pengguna yang diawali = + - @
 * akan dieksekusi sebagai formula saat file dibuka. Teks seperti itu diberi awalan apostrof.
 */
final class SafeCell
{
    public static function text(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Nominal sebagai angka agar bisa dijumlah di Excel. Konversi ke float hanya untuk keluaran
     * spreadsheet (bukan perhitungan); nilai sumbernya string desimal presisi.
     */
    public static function money(string $value): float
    {
        return (float) $value;
    }
}
