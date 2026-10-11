<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Laporan › Tipe order: kasir vs pesan dari QR meja, termasuk yang dibayar online (ADR 0014, S4). */
final class ChannelsTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->channels($start, $end, $this->context()->filters);
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Per kanal'), [
            'order_count' => self::sumCount($rows, 'order_count'),
            'revenue' => Money::format(self::sumMoney($rows, 'revenue')),
            'online_total' => Money::format(self::sumMoney($rows, 'online_total')),
        ])
            ->description('Kasir vs pesan sendiri dari QR meja')
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label('Kanal')->weight('medium'),
                self::countColumn('order_count', 'Transaksi'),
                self::moneyColumn('revenue', 'Omzet'),
                self::moneyColumn('online_total', 'Dibayar online'),
            ]);
    }
}
