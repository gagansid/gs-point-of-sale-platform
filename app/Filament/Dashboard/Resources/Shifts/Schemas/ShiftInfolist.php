<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Schemas;

use App\Filament\Shared\Infolists\MoneyEntry;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\Shift;
use App\Services\Shift\ShiftSummary;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Detail shift (infolist.md, pola sama dengan detail penjualan): atribut 4 kolom, rekap penjualan
 * per metode bayar sebagai tabel ringkas (ShiftSummary, sama dengan API), kas & riwayat di kanan.
 */
final class ShiftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $summary = fn (Shift $record): array => app(ShiftSummary::class)->for($record);

        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make('Detail shift')->columns(['default' => 2, 'md' => 4])->schema([
                        TextEntry::make('openedBy.name')->label('Dibuka oleh'),
                        TextEntry::make('opened_at')->label('Dibuka')->dateTime('j M Y, H.i'),
                        TextEntry::make('device.name')->label('Perangkat'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('closedBy.name')->label('Ditutup oleh')->placeholder('—'),
                        TextEntry::make('closed_at')->label('Ditutup')->dateTime('j M Y, H.i')->placeholder('—'),
                        TextEntry::make('close_note')->label('Catatan')->placeholder('—')->columnSpan(2),
                    ]),

                    Section::make('Penjualan')->schema([
                        Grid::make(['default' => 3])->schema([
                            TextEntry::make('order_count')->label('Transaksi selesai')
                                ->state(fn (Shift $record): int => $summary($record)['order_count']),
                            MoneyEntry::make('sales_total')->label('Total penjualan')->alignStart()
                                ->state(fn (Shift $record): string => $summary($record)['sales_total']),
                            TextEntry::make('open_bill_count')->label('Open bill belum lunas')
                                ->state(fn (Shift $record): int => $summary($record)['open_bill_count']),
                        ]),
                        View::make('filament.dashboard.shifts.payment-methods'),
                    ]),
                ]),

                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Kas')
                        ->extraAttributes(['class' => 'gs-summary'])
                        ->schema([
                            MoneyEntry::make('opening_cash')->label('Kas awal')->inlineLabel(),
                            MoneyEntry::make('cash_sales')->label('Penjualan tunai')->inlineLabel()
                                ->state(fn (Shift $record): string => $summary($record)['cash_sales']),
                            MoneyEntry::make('expected')->label('Seharusnya')->inlineLabel()
                                ->state(fn (Shift $record): string => $record->expected_cash ?? $summary($record)['expected_cash']),
                            MoneyEntry::make('actual_cash')->label('Aktual')->inlineLabel()->placeholder('—')
                                ->extraEntryWrapperAttributes(['class' => 'gs-summary-after']),
                            MoneyEntry::make('difference')->label('Selisih')->inlineLabel()->placeholder('—')
                                ->extraEntryWrapperAttributes(['class' => 'gs-summary-total']),
                        ]),
                    // Tanggal & pelaku dibuat/diubah (audit)
                    AuditInfo::card(),
                ]),
            ]);
    }
}
