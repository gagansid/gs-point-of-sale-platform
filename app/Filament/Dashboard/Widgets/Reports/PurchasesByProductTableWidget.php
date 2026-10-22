<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Laporan › Pembelian: pembelian per produk — qty, nilai, rata-rata harga beli (ADR 0015, Q83). */
final class PurchasesByProductTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->purchases($start, $end, $this->context()->filters)['products'];
    }

    protected function searchColumns(): array
    {
        return ['product_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Pembelian per produk'), [
            'qty' => self::sumCount($rows, 'qty'),
            'total_cost' => Money::formatWhole(self::sumMoney($rows, 'total_cost')),
        ])
            ->defaultSort('total_cost', 'desc')
            ->columns([
                TextColumn::make('product_name')->label('Produk')->searchable()->sortable()->weight('medium'),
                self::countColumn('qty', 'Qty'),
                self::moneyColumn('average_cost', 'Rata-rata harga beli'),
                self::moneyColumn('total_cost', 'Nilai beli'),
            ]);
    }
}
