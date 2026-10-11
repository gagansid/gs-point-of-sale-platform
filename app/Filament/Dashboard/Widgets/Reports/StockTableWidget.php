<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Stok: pergerakan stok per produk pada periode (terjual, kembali dari void, penyesuaian)
 * + stok saat ini; stok habis/menipis ditandai.
 */
final class StockTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->stock($start, $end, $this->context()->filters);
    }

    protected function searchColumns(): array
    {
        return ['product_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();
        $low = count(array_filter($rows, fn (array $r): bool => $r['is_low']));
        $signed = fn (int|string $state): string => ((int) $state > 0 ? '+' : '').number_format((int) $state, 0, ',', '.');

        return $this->reportTable($table, 'Pergerakan stok')
            ->description($low > 0 ? "{$low} produk habis/menipis" : 'Semua stok aman')
            ->defaultSort('product_name', 'asc')
            ->columns([
                TextColumn::make('product_name')->label('Produk')->searchable()->sortable()->weight('medium'),
                self::countColumn('sold', 'Terjual'),
                TextColumn::make('void_return')->label('Kembali (void)')->alignEnd()->sortable()->visibleFrom('md')->formatStateUsing($signed),
                TextColumn::make('adjustment')->label('Penyesuaian')->alignEnd()->sortable()->visibleFrom('md')->formatStateUsing($signed),
                TextColumn::make('net_change')->label('Perubahan')->alignEnd()->sortable()->visibleFrom('lg')->formatStateUsing($signed),
                TextColumn::make('stock')->label('Stok kini')->alignEnd()->sortable()->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Tidak dilacak' : number_format($state, 0, ',', '.'))
                    ->color(fn (?int $state, array $record): string => match (true) {
                        $state === null => 'gray',
                        $state <= 0 => 'danger',
                        $record['is_low'] => 'warning',
                        default => 'success',
                    }),
            ]);
    }
}
