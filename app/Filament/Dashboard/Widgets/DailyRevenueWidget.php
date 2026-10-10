<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * Riwayat pendapatan harian ala laporan keuangan: komponen struk + void per hari lokal,
 * baris total di bawah, bisa diurutkan per kolom.
 */
final class DailyRevenueWidget extends Widget
{
    use ReportWidget;

    /** Kolom yang boleh diurutkan → label header */
    public const COLUMNS = [
        'date' => 'Tanggal',
        'order_count' => 'Transaksi',
        'revenue' => 'Omzet',
        'discount_total' => 'Diskon',
        'service_total' => 'Service',
        'tax_total' => 'Pajak',
        'rounding_total' => 'Pembulatan',
        'void_total' => 'Void',
    ];

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.widgets.daily-revenue';

    public string $sortColumn = 'date';

    public string $sortDirection = 'desc';

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, self::COLUMNS)) {
            return;
        }

        $this->sortDirection = $this->sortColumn === $column && $this->sortDirection === 'desc' ? 'asc' : 'desc';
        $this->sortColumn = $column;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function getHistory(): array
    {
        [$start, $end] = $this->period()->utcRange();
        $rows = app(ReportService::class)->dailyBreakdown($start, $end, CurrentOutlet::timezone(), $this->reportFilters());

        $total = ['order_count' => 0, 'void_count' => 0];
        foreach (['revenue', 'discount_total', 'service_total', 'tax_total', 'rounding_total', 'void_total'] as $key) {
            $total[$key] = Money::add('0', ...array_column($rows, $key));
        }
        $total['order_count'] = array_sum(array_column($rows, 'order_count'));
        $total['void_count'] = array_sum(array_column($rows, 'void_count'));
        $total['average'] = Money::div($total['revenue'], $total['order_count']);

        $column = $this->sortColumn;
        usort($rows, function (array $a, array $b) use ($column): int {
            $cmp = in_array($column, ['date', 'order_count'], true)
                ? $a[$column] <=> $b[$column]
                : Money::compare((string) $a[$column], (string) $b[$column]);

            return $this->sortDirection === 'asc' ? $cmp : -$cmp;
        });

        return ['rows' => $rows, 'total' => $total];
    }
}
