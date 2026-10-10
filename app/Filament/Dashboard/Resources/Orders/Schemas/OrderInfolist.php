<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Detail transaksi bergaya nota (infolist.md): info transaksi, item, total, dan pembayaran dalam satu
 * kartu (view orders/nota, snapshot harga saat transaksi); info void & riwayat di kolom kanan.
 */
final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([View::make('filament.dashboard.orders.nota')]),

                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Dibatalkan')
                        ->visible(fn (Order $record): bool => $record->status === OrderStatus::Voided)
                        ->compact()
                        ->schema([
                            TextEntry::make('voided_at')->label('Waktu void')->dateTime('j M Y, H.i'),
                            TextEntry::make('void_reason')->label('Alasan'),
                        ]),
                    // Tanggal & pelaku dibuat/diubah (audit), mis. setelah void
                    AuditInfo::card(),
                ]),
            ]);
    }
}
