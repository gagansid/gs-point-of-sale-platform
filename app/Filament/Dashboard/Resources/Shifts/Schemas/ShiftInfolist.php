<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Schemas;

use App\Enums\OpeningCashSource;
use App\Filament\Shared\Infolists\MoneyEntry;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\Shift;
use App\Services\Shift\ShiftSummary;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Detail shift (infolist.md, pola sama dengan detail penjualan): atribut 4 kolom, rekap penjualan
 * per metode bayar sebagai tabel ringkas (ShiftSummary, sama dengan API), kasir bertugas & kas masuk/keluar
 * (ADR 0017), kas & riwayat di kanan.
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
                    Section::make('Detail shift')->icon(Heroicon::OutlinedClock)->columns(['default' => 2, 'md' => 4])->schema([
                        TextEntry::make('openedBy.name')->label('Dibuka oleh'),
                        TextEntry::make('opened_at')->label('Dibuka')->dateTime('j M Y, H.i'),
                        TextEntry::make('device.name')->label('Perangkat'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('closedBy.name')->label('Ditutup oleh')->placeholder('—'),
                        TextEntry::make('closed_at')->label('Ditutup')->dateTime('j M Y, H.i')->placeholder('—'),
                        TextEntry::make('close_note')->label('Catatan')->placeholder('—')->columnSpan(2),
                    ]),

                    Section::make('Penjualan')->icon(Heroicon::OutlinedShoppingBag)->schema([
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

                    // ADR 0017: ganti kasir tanpa hitung & kas masuk/keluar
                    Section::make('Kasir bertugas')->icon(Heroicon::OutlinedUsers)->schema([View::make('filament.dashboard.shifts.operators')]),
                    Section::make('Kas masuk/keluar')->icon(Heroicon::OutlinedArrowsUpDown)->schema([View::make('filament.dashboard.shifts.cash-movements')]),
                ]),

                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Kas')->icon(Heroicon::OutlinedBanknotes)
                        ->extraAttributes(['class' => 'gs-summary'])
                        ->schema([
                            MoneyEntry::make('opening_cash')->label('Kas awal')->inlineLabel()
                                ->hint(fn (Shift $record): ?string => match ($record->opening_cash_source) {
                                    OpeningCashSource::CarryOver => 'Sisa laci',
                                    OpeningCashSource::Adjusted => 'Dikoreksi dari '.Money::format((string) $record->carried_cash),
                                    default => null,
                                })
                                // Koreksi: alasan, pelaku & approver di tooltip ikon
                                ->hintIcon(
                                    fn (Shift $record): ?Heroicon => $record->opening_cash_source === OpeningCashSource::Adjusted ? Heroicon::InformationCircle : null,
                                    tooltip: fn (Shift $record): ?string => $record->opening_cash_source === OpeningCashSource::Adjusted
                                        ? '“'.$record->opening_cash_note.'” · '.($record->openingCashAdjustedBy->name ?? '–')
                                            .($record->openingCashApprovedBy !== null ? ', disetujui '.$record->openingCashApprovedBy->name : '')
                                        : null,
                                ),
                            MoneyEntry::make('cash_sales')->label('Penjualan tunai')->inlineLabel()
                                ->state(fn (Shift $record): string => $summary($record)['cash_sales']),
                            MoneyEntry::make('cash_in')->label('Kas masuk')->inlineLabel()
                                ->state(fn (Shift $record): string => $summary($record)['cash_in']),
                            MoneyEntry::make('cash_out')->label('Kas keluar')->inlineLabel()
                                ->state(fn (Shift $record): string => $summary($record)['cash_out']),
                            MoneyEntry::make('expected')->label('Seharusnya')->inlineLabel()
                                ->state(fn (Shift $record): string => $record->expected_cash ?? $summary($record)['expected_cash']),
                            MoneyEntry::make('actual_cash')->label('Aktual')->inlineLabel()->placeholder('—')
                                ->extraEntryWrapperAttributes(['class' => 'gs-summary-after']),
                            MoneyEntry::make('difference')->label('Selisih')->inlineLabel()->placeholder('—')
                                ->extraEntryWrapperAttributes(['class' => 'gs-summary-total']),
                            MoneyEntry::make('cash_deposited')->label('Disetor')->inlineLabel()->placeholder('—'),
                            MoneyEntry::make('closing_float')->label('Ditinggal di laci')->inlineLabel()->placeholder('—'),
                        ]),
                    // Tanggal & pelaku dibuat/diubah (audit)
                    AuditInfo::card(),
                ]),
            ]);
    }
}
