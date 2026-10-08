<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Dashboard\Resources\Orders\Actions\VoidOrderAction;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Models\Order;
use App\Models\User;
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
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('order_number')->label('Nomor order')->fontFamily('mono')->searchable()->copyable(),
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y, H.i')->sortable(),
                TextColumn::make('user.name')->label('Kasir')->toggleable(),
                TextColumn::make('order_type')->label('Tipe')->badge()->color('gray')->toggleable(),
                TextColumn::make('table_label')->label('Meja')->placeholder('—')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge(),
                MoneyColumn::make('grand_total')->label('Total')->sortable()
                    ->color(fn (Order $record): ?string => $record->status === OrderStatus::Voided ? 'gray' : null),
            ])
            ->filters([
                DateRangeFilter::make('created_at'),
                SelectFilter::make('status')->label('Status')->options(OrderStatus::class),
                SelectFilter::make('order_type')->label('Tipe')->options(OrderType::class),
                SelectFilter::make('user_id')->label('Kasir')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Lihat'),
                ActionGroup::make([VoidOrderAction::make()])->tooltip('Aksi lain'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->persistFiltersInSession()
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent)
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Transaksi dari aplikasi kasir akan muncul di sini');
    }
}
