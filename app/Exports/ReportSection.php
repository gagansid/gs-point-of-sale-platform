<?php

declare(strict_types=1);

namespace App\Exports;

/**
 * Satu bagian laporan untuk export: judul, header kolom, baris, dan kolom nominal.
 * Nominal tetap string desimal di sini; Excel mengubahnya ke angka (SafeCell::money),
 * PDF memformatnya Rupiah. Teks pengguna di-escape per format (SafeCell / Blade {{ }}).
 */
final readonly class ReportSection
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|null>>  $rows
     * @param  list<int>  $moneyColumns  indeks kolom (0-based) berisi nominal
     * @param  list<string|int|null>|null  $totals  baris total (opsional)
     */
    public function __construct(
        public string $title,
        public array $headings,
        public array $rows,
        public array $moneyColumns = [],
        public ?array $totals = null,
    ) {}

    public function isMoney(int $column): bool
    {
        return in_array($column, $this->moneyColumns, true);
    }
}
