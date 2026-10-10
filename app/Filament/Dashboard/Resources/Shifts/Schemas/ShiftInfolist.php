<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Schemas;

use App\Filament\Shared\Infolists\MoneyEntry;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\Shift;
use App\Services\Shift\ShiftSummary;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail shift + rekap penjualan per metode bayar (ShiftSummary, sama dengan API).
 */
final class ShiftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $summary = fn (Shift $record): array => app(ShiftSummary::class)->for($record);

        return $schema
            ->columns(2)
            ->components([
                Section::make('Detail shift')->columns(2)->schema([
                    TextEntry::make('openedBy.name')->label('Dibuka oleh'),
                    TextEntry::make('device.name')->label('Perangkat'),
                    TextEntry::make('opened_at')->label('Dibuka')->dateTime('j M Y, H.i'),
                    TextEntry::make('closed_at')->label('Ditutup')->dateTime('j M Y, H.i')->placeholder('—'),
                    TextEntry::make('status')->label('Status')->badge(),
                    TextEntry::make('closedBy.name')->label('Ditutup oleh')->placeholder('—'),
                    TextEntry::make('close_note')->label('Catatan')->placeholder('—')->columnSpanFull(),
                ]),

                Section::make('Kas')->schema([
                    MoneyEntry::make('opening_cash')->label('Kas awal')->inlineLabel(),
                    MoneyEntry::make('cash_sales')->label('Penjualan tunai')->inlineLabel()
                        ->state(fn (Shift $record): string => $summary($record)['cash_sales']),
                    MoneyEntry::make('expected')->label('Seharusnya')->inlineLabel()
                        ->state(fn (Shift $record): string => $record->expected_cash ?? $summary($record)['expected_cash']),
                    MoneyEntry::make('actual_cash')->label('Aktual')->inlineLabel()->placeholder('—'),
                    MoneyEntry::make('difference')->label('Selisih')->inlineLabel()->placeholder('—')->weight('bold'),
                ]),

                Section::make('Penjualan')->columnSpanFull()->schema([
                    TextEntry::make('order_count')->label('Transaksi selesai')->inlineLabel()
                        ->state(fn (Shift $record): int => $summary($record)['order_count']),
                    MoneyEntry::make('sales_total')->label('Total penjualan')->inlineLabel()
                        ->state(fn (Shift $record): string => $summary($record)['sales_total']),
                    TextEntry::make('open_bill_count')->label('Open bill belum lunas')->inlineLabel()
                        ->state(fn (Shift $record): int => $summary($record)['open_bill_count']),
                    RepeatableEntry::make('payment_methods')->label('Per metode bayar')->columns(3)
                        ->state(fn (Shift $record): array => $summary($record)['payment_methods'])
                        ->schema([
                            TextEntry::make('name')->label('Metode'),
                            TextEntry::make('count')->label('Transaksi'),
                            MoneyEntry::make('amount')->label('Nominal'),
                        ]),
                ]),
                // Tanggal dibuat & diubah (audit)
                AuditInfo::make(),
            ]);
    }
}
