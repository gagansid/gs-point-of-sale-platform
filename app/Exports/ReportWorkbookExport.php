<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Workbook laporan (Q52): sheet "Info" (outlet, periode, filter aktif, dicetak oleh & waktu) lalu
 * satu sheet per ReportSection. Nominal ditulis sebagai angka, teks pengguna lewat SafeCell
 * (anti formula injection).
 */
final class ReportWorkbookExport implements WithMultipleSheets
{
    /**
     * @param  list<array{0: string, 1: string}>  $info  pasangan label → nilai
     * @param  list<ReportSection>  $sections
     */
    public function __construct(
        private readonly array $info,
        private readonly array $sections,
    ) {}

    /** @return list<ReportSheet> */
    public function sheets(): array
    {
        $sheets = [new ReportSheet('Info', ['Keterangan', 'Nilai'], array_map(
            fn (array $row): array => [SafeCell::text($row[0]), SafeCell::text($row[1])],
            $this->info,
        ), moneyColumns: [])];

        $used = ['info' => true];
        foreach ($this->sections as $section) {
            $rows = array_map(fn (array $row): array => $this->cells($section, $row), $section->rows);
            if ($section->totals !== null) {
                $rows[] = $this->cells($section, $section->totals);
            }

            $sheets[] = new ReportSheet(
                $this->uniqueTitle($section->title, $used),
                $section->headings,
                $rows,
                array_map(fn (int $i): string => Coordinate::stringFromColumnIndex($i + 1), $section->moneyColumns),
            );
        }

        return $sheets;
    }

    /**
     * @param  list<string|int|null>  $row
     * @return list<string|int|float|null>
     */
    private function cells(ReportSection $section, array $row): array
    {
        $cells = [];
        foreach ($row as $i => $value) {
            $cells[] = match (true) {
                // Nominal = string desimal di kolom uang; angka bulat (jumlah) tetap angka
                $section->isMoney($i) && is_string($value) && is_numeric($value) => SafeCell::money($value),
                is_string($value) => SafeCell::text($value),
                default => $value,
            };
        }

        return $cells;
    }

    /**
     * Judul sheet Excel: maks. 31 karakter, tanpa []:*?/\, unik.
     *
     * @param  array<string, true>  $used
     */
    private function uniqueTitle(string $title, array &$used): string
    {
        $base = mb_substr(trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $title)), 0, 28);
        $candidate = $base;
        $n = 2;
        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $base.' '.$n++;
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
