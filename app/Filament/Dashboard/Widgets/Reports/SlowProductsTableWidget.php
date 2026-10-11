<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Produk: produk aktif yang paling sedikit terjual (termasuk 0) — kandidat promo.
 */
final class SlowProductsTableWidget extends ReportTableWidget
{
    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->slowProducts($start, $end, 20, $this->context()->filters);
    }

    protected function searchColumns(): array
    {
        return ['product_name'];
    }

    public function table(Table $table): Table
    {
        return $this->reportTable($table, 'Produk kurang laku')
            ->description('20 produk aktif dengan penjualan terendah')
            ->defaultSort('qty', 'asc')
            ->columns([
                TextColumn::make('product_name')->label('Produk')->searchable()->sortable(),
                self::countColumn('qty', 'Qty')
                    ->badge()
                    ->color(fn (int|string $state): string => (int) $state === 0 ? 'danger' : 'gray'),
                self::moneyColumn('revenue', 'Omzet'),
            ]);
    }
}
