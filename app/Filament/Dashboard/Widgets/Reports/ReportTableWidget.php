<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Tabel laporan dari data teragregasi (array dari ReportService): cari, urut, dan paginasi
 * dikerjakan di sini karena datanya bukan query Eloquent (table.md: nominal rata kanan,
 * Money::format, kolom prioritas rendah visibleFrom('md')). Total di baris <tfoot> (totalRow).
 */
abstract class ReportTableWidget extends TableWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** @var list<array<string, mixed>>|null Dihitung sekali per request */
    private ?array $rowsCache = null;

    /** @return list<array<string, mixed>> */
    abstract protected function rows(): array;

    /** @return list<array<string, mixed>> */
    protected function cachedRows(): array
    {
        return $this->rowsCache ??= $this->rows();
    }

    /** Konfigurasi dasar: sumber data array + paginasi 10/25/50. */
    protected function reportTable(Table $table, string $heading): Table
    {
        return $table
            ->heading($heading)
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows(
                $search, $sortColumn, $sortDirection, (int) $page, $recordsPerPage,
            ))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada data pada periode ini')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }

    /**
     * Baris total di bawah tabel, dari seluruh baris periode (bukan hanya halaman/pencarian aktif).
     *
     * @param  array<string, string>  $totals  nama kolom => teks terformat
     */
    protected function totalRow(Table $table, array $totals, string $label = 'Total'): Table
    {
        return $table->contentFooter(view('filament.dashboard.widgets.reports.table-total', ['totals' => $totals, 'label' => $label]));
    }

    /** @param list<array<string, mixed>> $rows */
    protected static function sumMoney(array $rows, string $column): string
    {
        return Money::add('0', ...array_map('strval', array_column($rows, $column)));
    }

    /** @param list<array<string, mixed>> $rows */
    protected static function sumCount(array $rows, string $column): string
    {
        return number_format(array_sum(array_map('intval', array_column($rows, $column))), 0, ',', '.');
    }

    /** @return list<string> kolom yang dicari (teks) */
    protected function searchColumns(): array
    {
        return [];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    protected function paginateRows(?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int|string $perPage): LengthAwarePaginator
    {
        $rows = collect($this->cachedRows());

        if (filled($search) && $this->searchColumns() !== []) {
            $needle = mb_strtolower(trim((string) $search));
            $rows = $rows->filter(fn (array $row): bool => collect($this->searchColumns())
                ->contains(fn (string $column): bool => str_contains(mb_strtolower((string) ($row[$column] ?? '')), $needle)));
        }

        if (filled($sortColumn)) {
            $rows = $rows->sort(function (array $a, array $b) use ($sortColumn, $sortDirection): int {
                $x = $a[$sortColumn] ?? null;
                $y = $b[$sortColumn] ?? null;
                // Nominal string desimal dibandingkan dengan bcmath, bukan float
                $cmp = is_numeric($x) && is_numeric($y) ? Money::compare((string) $x, (string) $y) : strnatcasecmp((string) $x, (string) $y);

                return $sortDirection === 'desc' ? -$cmp : $cmp;
            });
        }

        $total = $rows->count();
        $size = $perPage === 'all' ? max($total, 1) : max((int) $perPage, 1);
        $page = max($page, 1);

        return new LengthAwarePaginator($rows->slice(($page - 1) * $size, $size)->values()->all(), $total, $size, $page);
    }

    protected static function moneyColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable()
            ->formatStateUsing(fn (?string $state): string => $state === null ? '–' : Money::formatWhole($state))
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    protected static function countColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable()
            ->formatStateUsing(fn (int|string|null $state): string => number_format((int) $state, 0, ',', '.'))
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    /** Persen kontribusi "12,5%" dari nilai 0–100 (string desimal). */
    protected static function shareColumn(string $name = 'share', string $label = 'Kontribusi'): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable()
            ->formatStateUsing(fn (?string $state): string => str_replace('.', ',', (string) $state).'%')
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    /** Persen bagian (1 desimal) dari total; 0 bila total 0. */
    protected static function share(string $value, string $total): string
    {
        return Money::compare($total, '0') > 0 ? Money::round(bcdiv(bcmul($value, '100', 4), $total, 4), 1) : '0.0';
    }
}
