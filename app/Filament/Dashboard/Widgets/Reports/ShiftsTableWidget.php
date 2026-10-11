<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Enums\ShiftStatus;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Laporan › Shift: per shift yang dibuka pada periode — kasir, buka/tutup, kas awal, penjualan
 * tunai, kas seharusnya vs aktual, selisih (badge success/danger), ditutup paksa.
 */
final class ShiftsTableWidget extends ReportTableWidget
{
    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    protected function rows(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->shifts($start, $end, $this->context()->filters);
    }

    protected function searchColumns(): array
    {
        return ['cashier', 'outlet'];
    }

    public function table(Table $table): Table
    {
        $rows = $this->cachedRows();
        $differences = array_filter(array_column($rows, 'difference'), fn (?string $d): bool => $d !== null && Money::compare($d, '0') !== 0);
        $time = fn (?string $iso): string => $iso === null ? '–' : CarbonImmutable::parse($iso)->setTimezone(CurrentOutlet::timezone())->locale('id')->translatedFormat('j M H.i');

        return $this->reportTable($table, 'Rekap shift')
            ->description(count($rows).' shift · '.count($differences).' dengan selisih kas')
            ->defaultSort('opened_at', 'desc')
            ->recordUrl(fn (array $record): ?string => ShiftResource::canViewAny() ? ShiftResource::getUrl('view', ['record' => $record['shift_id']]) : null)
            ->columns([
                TextColumn::make('cashier')->label('Kasir')->searchable()->sortable()->weight('medium')
                    ->description(fn (array $record): string => $record['outlet']),
                TextColumn::make('opened_at')->label('Buka')->sortable()->formatStateUsing(fn (?string $state): string => $time($state)),
                TextColumn::make('closed_at')->label('Tutup')->sortable()->visibleFrom('md')->formatStateUsing(fn (?string $state): string => $time($state)),
                self::countColumn('order_count', 'Transaksi')->visibleFrom('lg'),
                self::moneyColumn('opening_cash', 'Kas awal')->visibleFrom('xl'),
                self::moneyColumn('cash_sales', 'Tunai')->visibleFrom('lg'),
                self::moneyColumn('expected_cash', 'Seharusnya'),
                self::moneyColumn('actual_cash', 'Aktual')->visibleFrom('md'),
                TextColumn::make('difference')->label('Selisih')->alignEnd()->sortable()->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Berjalan' : Money::format($state))
                    ->color(fn (?string $state): string => match (true) {
                        $state === null => 'gray',
                        Money::compare($state, '0') === 0 => 'success',
                        default => 'danger',
                    }),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => ShiftStatus::from($state)->getLabel())
                    ->color(fn (string $state): string => $state === ShiftStatus::ForceClosed->value ? 'danger' : ($state === ShiftStatus::Open->value ? 'info' : 'gray')),
            ]);
    }
}
