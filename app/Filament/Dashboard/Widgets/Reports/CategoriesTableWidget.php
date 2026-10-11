<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Kategori: qty & omzet per kategori dari snapshot order_items.category_name (Q51).
 */
final class CategoriesTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();
        $rows = app(ReportService::class)->categories($start, $end, $this->context()->filters);
        $total = Money::add('0', ...array_column($rows, 'revenue'));

        return array_map(fn (array $r): array => [...$r, 'share' => self::share($r['revenue'], $total)], $rows);
    }

    protected function searchColumns(): array
    {
        return ['category_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Penjualan per kategori'), [
            'qty' => self::sumCount($rows, 'qty'),
            'revenue' => Money::format(self::sumMoney($rows, 'revenue')),
            'share' => '100%',
        ])
            ->defaultSort('revenue', 'desc')
            ->columns([
                TextColumn::make('category_name')->label('Kategori')->searchable()->sortable()->weight('medium'),
                self::countColumn('product_count', 'Produk')->visibleFrom('md'),
                self::countColumn('qty', 'Qty'),
                self::moneyColumn('revenue', 'Omzet'),
                self::shareColumn(),
            ]);
    }
}
