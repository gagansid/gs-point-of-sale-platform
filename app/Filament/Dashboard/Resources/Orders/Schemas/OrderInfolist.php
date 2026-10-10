<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Shared\Infolists\MoneyEntry;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Detail transaksi (infolist.md): atribut ringkas 4 kolom, item & pembayaran sebagai tabel padat
 * (snapshot harga saat transaksi), ringkasan nominal + riwayat di kolom kanan.
 */
final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make('Detail transaksi')
                        ->columns(['default' => 2, 'md' => 4])
                        ->schema([
                            TextEntry::make('order_number')->label('Nomor order')->fontFamily('mono')->copyable()
                                ->copyMessage('Nomor order disalin')->columnSpan(['default' => 2, 'md' => 1]),
                            TextEntry::make('created_at')->label('Waktu')->dateTime('j M Y, H.i'),
                            TextEntry::make('user.name')->label('Kasir'),
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('order_type')->label('Tipe')->badge()->color('gray'),
                            TextEntry::make('table_label')->label('Meja')->placeholder('—'),
                            TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpan(2),
                        ]),

                    Section::make('Dibatalkan')
                        ->visible(fn (Order $record): bool => $record->status === OrderStatus::Voided)
                        ->columns(['default' => 2, 'md' => 4])
                        ->schema([
                            TextEntry::make('voided_at')->label('Waktu void')->dateTime('j M Y, H.i'),
                            TextEntry::make('void_reason')->label('Alasan')->columnSpan(['default' => 2, 'md' => 3]),
                        ]),

                    Section::make('Item')
                        ->description(fn (Order $record): string => $record->items->sum('qty').' item')
                        ->schema([View::make('filament.dashboard.orders.items')]),

                    Section::make('Pembayaran')
                        ->schema([View::make('filament.dashboard.orders.payments')]),
                ]),

                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Ringkasan')
                        ->extraAttributes(['class' => 'gs-summary'])
                        ->schema([
                            MoneyEntry::make('subtotal')->label('Subtotal')->inlineLabel(),
                            MoneyEntry::make('discount_total')->label('Diskon')->inlineLabel(),
                            MoneyEntry::make('service_total')->label('Service')->inlineLabel(),
                            MoneyEntry::make('tax_total')->label(fn (Order $record): string => 'Pajak '.rtrim(rtrim($record->tax_rate, '0'), '.').'%'.($record->tax_inclusive ? ' (termasuk)' : ''))->inlineLabel(),
                            MoneyEntry::make('rounding')->label('Pembulatan')->inlineLabel(),
                            MoneyEntry::make('grand_total')->label('Total')->inlineLabel()
                                ->extraEntryWrapperAttributes(['class' => 'gs-summary-total']),
                            MoneyEntry::make('paid_total')->label('Dibayar')->inlineLabel()
                                ->extraEntryWrapperAttributes(['class' => 'gs-summary-after']),
                            MoneyEntry::make('change_total')->label('Kembalian')->inlineLabel(),
                        ]),
                    // Tanggal & pelaku dibuat/diubah (audit), mis. setelah void
                    AuditInfo::card(),
                ]),
            ]);
    }
}
