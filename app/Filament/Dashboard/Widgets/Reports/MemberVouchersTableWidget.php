<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Laporan › Member: pemakaian voucher per kode di periode (ADR 0013, M7). */
final class MemberVouchersTableWidget extends ReportTableWidget
{
    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->members($start, $end, $this->context()->filters)['vouchers'];
    }

    protected function searchColumns(): array
    {
        return ['voucher_code'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();

        return $this->totalRow($this->reportTable($table, 'Pemakaian voucher'), [
            'uses' => self::sumCount($rows, 'uses'),
            'discount' => Money::format(self::sumMoney($rows, 'discount')),
        ])
            ->defaultSort('uses', 'desc')
            ->columns([
                TextColumn::make('voucher_code')->label('Voucher')->searchable()->sortable()->weight('medium'),
                self::countColumn('uses', 'Dipakai'),
                self::moneyColumn('discount', 'Potongan'),
            ]);
    }
}
