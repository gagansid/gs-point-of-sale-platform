<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Produk: qty, omzet, % kontribusi per produk (nama snapshot transaksi terakhir).
 * HPP/margin menyusul dari snapshot order_items.unit_cost (Q53).
 */
final class ProductsTableWidget extends ReportTableWidget
{
    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();
        $rows = app(ReportService::class)->products($start, $end, null, $this->context()->filters);
        $total = Money::add('0', ...array_column($rows, 'revenue'));

        return array_map(fn (array $r): array => [...$r, 'share' => self::share($r['revenue'], $total)], $rows);
    }

    protected function searchColumns(): array
    {
        return ['product_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Penjualan per produk'), [
            'qty' => self::sumCount($rows, 'qty'),
            'revenue' => Money::formatWhole(self::sumMoney($rows, 'revenue')),
            'share' => '100%',
        ], 'Total ('.count($rows).' produk)')
            ->defaultSort('revenue', 'desc')
            ->columns([
                TextColumn::make('product_name')->label('Produk')->searchable()->sortable()->weight('medium'),
                self::countColumn('qty', 'Qty'),
                self::moneyColumn('revenue', 'Omzet'),
                self::shareColumn()->visibleFrom('md'),
            ]);
    }
}
