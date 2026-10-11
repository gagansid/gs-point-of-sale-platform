<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Opsi: opsi/add-on terlaris dan tambahan omzetnya (order_item_options × qty item).
 */
final class OptionsTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->options($start, $end, $this->context()->filters);
    }

    protected function searchColumns(): array
    {
        return ['option_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->reportTable($table, 'Opsi & add-on')
            ->description('Tambahan omzet dari opsi: '.Money::format(Money::add('0', ...array_column($rows, 'revenue'))))
            ->defaultSort('qty', 'desc')
            ->columns([
                TextColumn::make('option_name')->label('Opsi')->searchable()->sortable()->weight('medium'),
                self::countColumn('qty', 'Dipilih'),
                self::moneyColumn('revenue', 'Tambahan omzet'),
            ]);
    }
}
