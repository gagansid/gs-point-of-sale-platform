<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Produk: qty, omzet, % kontribusi per produk (nama snapshot transaksi terakhir).
 * HPP/margin belum ada karena data modal belum dicatat.
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

        return $this->reportTable($table, 'Penjualan per produk')
            ->description(count($rows).' produk · '.number_format(array_sum(array_column($rows, 'qty')), 0, ',', '.').' item · '
                .Money::format(Money::add('0', ...array_column($rows, 'revenue'))))
            ->defaultSort('revenue', 'desc')
            ->columns([
                TextColumn::make('product_name')->label('Produk')->searchable()->sortable()->weight('medium'),
                self::countColumn('qty', 'Qty'),
                self::moneyColumn('revenue', 'Omzet'),
                self::shareColumn()->visibleFrom('md'),
            ]);
    }
}
