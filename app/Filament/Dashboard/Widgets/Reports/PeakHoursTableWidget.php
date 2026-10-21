<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Waktu: data di balik grafik jam ramai (chart.md › Aksesibilitas). Hanya jam yang ada
 * transaksinya; urut per jam seperti grafik (kolom Transaksi/Omzet bisa diurutkan).
 */
final class PeakHoursTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();
        $hours = app(ReportService::class)->hourly($start, $end, CurrentOutlet::timezone(), $this->context()->filters);

        return array_values(array_map(fn (array $h): array => [
            'hour' => $h['hour'],
            'label' => sprintf('%02d.00–%02d.59', $h['hour'], $h['hour']),
            'order_count' => $h['order_count'],
            'revenue' => $h['revenue'],
        ], array_filter($hours, fn (array $h): bool => $h['order_count'] > 0)));
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Data jam ramai'), [
            'order_count' => self::sumCount($rows, 'order_count'),
            'revenue' => Money::formatWhole(self::sumMoney($rows, 'revenue')),
        ])
            ->description('Angka yang sama dengan grafik di atas (jam lokal outlet)')
            ->columns([
                TextColumn::make('label')->label('Jam')->weight('medium'),
                self::countColumn('order_count', 'Transaksi'),
                self::moneyColumn('revenue', 'Omzet'),
            ]);
    }
}
