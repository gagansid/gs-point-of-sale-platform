<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Laporan › Stok: pembelian (stok masuk diposting) per supplier pada periode (ADR 0015, Q83). */
final class PurchasesBySupplierTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->purchases($start, $end, $this->context()->filters)['suppliers'];
    }

    protected function searchColumns(): array
    {
        return ['supplier_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Pembelian per supplier'), [
            'receipt_count' => self::sumCount($rows, 'receipt_count'),
            'qty' => self::sumCount($rows, 'qty'),
            'total_cost' => Money::format(self::sumMoney($rows, 'total_cost')),
        ])
            ->description('Stok masuk yang diposting (tanpa yang dibatalkan), menurut tanggal terima')
            ->defaultSort('total_cost', 'desc')
            ->columns([
                TextColumn::make('supplier_name')->label('Supplier')->searchable()->sortable()->weight('medium'),
                self::countColumn('receipt_count', 'Dokumen'),
                self::countColumn('qty', 'Qty'),
                self::moneyColumn('total_cost', 'Nilai beli'),
            ]);
    }
}
