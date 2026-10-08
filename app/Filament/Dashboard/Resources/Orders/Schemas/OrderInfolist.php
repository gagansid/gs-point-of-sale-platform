<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Shared\Infolists\MoneyEntry;
use App\Models\Order;
use App\Models\OrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail transaksi (infolist.md): snapshot item & harga saat transaksi, ringkasan di kanan.
 */
final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Detail transaksi')->columns(3)->schema([
                        TextEntry::make('order_number')->label('Nomor order')->fontFamily('mono')->copyable(),
                        TextEntry::make('created_at')->label('Waktu')->dateTime('j M Y, H.i'),
                        TextEntry::make('user.name')->label('Kasir'),
                        TextEntry::make('order_type')->label('Tipe')->badge()->color('gray'),
                        TextEntry::make('table_label')->label('Meja')->placeholder('—'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
                    ]),

                    Section::make('Dibatalkan')
                        ->visible(fn (Order $record): bool => $record->status === OrderStatus::Voided)
                        ->columns(3)
                        ->schema([
                            TextEntry::make('void_reason')->label('Alasan')->columnSpanFull(),
                            TextEntry::make('voided_at')->label('Waktu void')->dateTime('j M Y, H.i'),
                        ]),

                    Section::make('Item')->schema([
                        RepeatableEntry::make('items')->hiddenLabel()->columns(5)->schema([
                            TextEntry::make('product_name')->label('Produk')->weight('medium'),
                            TextEntry::make('options_label')->label('Opsi')->placeholder('—')
                                ->state(fn (OrderItem $record): ?string => $record->options->pluck('option_name')->join(', ') ?: null),
                            TextEntry::make('qty')->label('Qty')->numeric(locale: 'id'),
                            MoneyEntry::make('unit_price')->label('Harga'),
                            MoneyEntry::make('line_total')->label('Total'),
                        ]),
                    ]),

                    Section::make('Pembayaran')->schema([
                        RepeatableEntry::make('payments')->hiddenLabel()->columns(4)->schema([
                            TextEntry::make('method.name')->label('Metode'),
                            MoneyEntry::make('amount')->label('Nominal'),
                            MoneyEntry::make('change')->label('Kembalian'),
                            TextEntry::make('status')->label('Status')->badge(),
                        ]),
                    ]),
                ]),

                Section::make('Ringkasan')->columnSpan(1)->schema([
                    MoneyEntry::make('subtotal')->label('Subtotal')->inlineLabel(),
                    MoneyEntry::make('discount_total')->label('Diskon')->inlineLabel(),
                    MoneyEntry::make('service_total')->label('Service')->inlineLabel(),
                    MoneyEntry::make('tax_total')->label(fn (Order $record): string => 'Pajak '.rtrim(rtrim($record->tax_rate, '0'), '.').'%'.($record->tax_inclusive ? ' (termasuk)' : ''))->inlineLabel(),
                    MoneyEntry::make('rounding')->label('Pembulatan')->inlineLabel(),
                    MoneyEntry::make('grand_total')->label('Total')->inlineLabel()->weight('bold')->size('lg'),
                    MoneyEntry::make('paid_total')->label('Dibayar')->inlineLabel(),
                    MoneyEntry::make('change_total')->label('Kembalian')->inlineLabel(),
                ]),
            ]);
    }
}
