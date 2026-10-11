<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Laba kotor: per produk dari snapshot harga modal (R1, Q53). Produk tanpa harga modal
 * ditandai "–" agar owner tahu harus melengkapi Harga modal di menu Produk.
 */
final class ProfitTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->profit($start, $end, $this->context()->filters)['products'];
    }

    protected function searchColumns(): array
    {
        return ['product_name', 'category_name'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();
        $profit = fn (array $r): ?string => $r['gross_profit'];
        $costed = array_values(array_filter($rows, fn (array $r): bool => $r['gross_profit'] !== null));

        return $this->totalRow($this->reportTable($table, 'Laba kotor per produk'), [
            'qty' => self::sumCount($rows, 'qty'),
            'net_sales' => Money::format(self::sumMoney($rows, 'net_sales')),
            'cogs' => Money::format(self::sumMoney($rows, 'cogs')),
            'gross_profit' => Money::format(Money::add('0', ...array_map($profit, $costed))),
        ])
            ->description('Penjualan bersih = setelah diskon order, tanpa pajak & service. Lengkapi Harga modal di menu Produk agar laba akurat.')
            ->defaultSort('gross_profit', 'desc')
            ->columns([
                TextColumn::make('product_name')->label('Produk')->searchable()->sortable()->weight('medium')
                    ->description(fn (array $record): ?string => $record['uncosted_qty'] > 0 ? $record['uncosted_qty'].' item tanpa harga modal' : null),
                TextColumn::make('category_name')->label('Kategori')->sortable()->visibleFrom('md'),
                self::countColumn('qty', 'Qty'),
                self::moneyColumn('net_sales', 'Penjualan bersih'),
                self::moneyColumn('cogs', 'HPP')->visibleFrom('md'),
                self::moneyColumn('gross_profit', 'Laba kotor')
                    ->color(fn (?string $state): ?string => $state !== null && Money::compare($state, '0') < 0 ? 'danger' : null),
                TextColumn::make('margin')->label('Margin')->alignEnd()->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '–' : str_replace('.', ',', $state).'%')
                    ->placeholder('–')
                    ->extraAttributes(['class' => 'tabular-nums']),
            ]);
    }
}
