<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Dashboard\Resources\Orders\Actions\VoidOrderAction;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar penjualan (table.md: default filter hari ini, nomor order mono, uang rata kanan).
 */
final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('order_number')->label('Nomor order')->fontFamily('mono')->searchable()->sortable()->copyable(),
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y, H.i')->sortable(),
                // Tampil saat "Semua outlet" dipilih di topbar dan bisnis punya lebih dari satu outlet (ADR 0010)
                TextColumn::make('outlet.name')->label('Outlet')->badge()->color('gray')->toggleable()
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && Outlet::query()->count() > 1),
                TextColumn::make('user.name')->label('Kasir')->sortable()->toggleable(),
                TextColumn::make('order_type')->label('Tipe')->badge()->color('gray')->sortable()->toggleable(),
                TextColumn::make('table_label')->label('Meja')->placeholder('—')->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                MoneyColumn::make('grand_total')->label('Total')->sortable()
                    ->color(fn (Order $record): ?string => $record->status === OrderStatus::Voided ? 'gray' : null),
            ])
            ->filters([
                DateRangeFilter::make('created_at'),
                SelectFilter::make('order_type')->label('Tipe')->options(OrderType::class),
                SelectFilter::make('user_id')->label('Kasir')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Lihat'),
                ActionGroup::make([VoidOrderAction::make()])->tooltip('Aksi lain'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc');

        return TableEmptyState::apply($table, Heroicon::OutlinedReceiptPercent, 'transaksi', 'Transaksi dari aplikasi kasir akan muncul di sini');
    }
}
