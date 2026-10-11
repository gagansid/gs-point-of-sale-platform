<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Karyawan: per kasir (pembuat order) — transaksi, omzet, rata-rata, diskon, void.
 */
final class EmployeesTableWidget extends ReportTableWidget
{
    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->employees($start, $end, $this->context()->filters);
    }

    protected function searchColumns(): array
    {
        return ['name'];
    }

    public function table(Table $table): Table
    {
        return $this->reportTable($table, 'Kinerja karyawan')
            ->defaultSort('revenue', 'desc')
            ->columns([
                TextColumn::make('name')->label('Kasir')->searchable()->sortable()->weight('medium'),
                self::countColumn('order_count', 'Transaksi'),
                self::moneyColumn('revenue', 'Omzet'),
                self::moneyColumn('average', 'Rata-rata')->visibleFrom('md'),
                self::moneyColumn('discount_total', 'Diskon')->visibleFrom('lg'),
                self::countColumn('void_count', 'Void')
                    ->badge()
                    ->color(fn (int|string $state): string => (int) $state > 0 ? 'warning' : 'gray'),
                self::moneyColumn('void_total', 'Nilai void')->visibleFrom('lg'),
            ]);
    }
}
