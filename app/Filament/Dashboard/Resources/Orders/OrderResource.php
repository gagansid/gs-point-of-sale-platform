<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders;

use App\Filament\Dashboard\Resources\Orders\Pages\ListOrders;
use App\Filament\Dashboard\Resources\Orders\Pages\ViewOrder;
use App\Filament\Dashboard\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Dashboard\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Support\CurrentOutlet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Menu Transaksi → Penjualan (hanya baca + void). Order dibuat dari aplikasi kasir.
 */
final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?string $modelLabel = 'transaksi';

    protected static ?string $pluralModelLabel = 'penjualan';

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    /**
     * Outlet pilihan sidebar ("Semua outlet" = semua outlet yang dipegang user), ADR 0010.
     *
     * @return Builder<Order>
     */
    public static function getEloquentQuery(): Builder
    {
        return CurrentOutlet::scope(Order::query());
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
