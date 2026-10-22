<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\SelfOrders;

use App\Enums\SelfOrderStatus;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\SelfOrders\Pages\ListSelfOrders;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\DiningTable;
use App\Models\Option;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Transaksi → Pesanan meja (ADR 0014, Q68; izin order.view_all): riwayat pesanan QR meja — menunggu, diterima
 * (tautan ke order), ditolak beserta alasannya, dan kedaluwarsa. Hanya baca; diterima/ditolak tetap di kasir.
 */
final class SelfOrderResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = SelfOrder::class;

    protected static ?string $slug = 'table-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?string $navigationLabel = 'Pesanan meja';

    protected static ?string $modelLabel = 'pesanan meja';

    protected static ?string $pluralModelLabel = 'pesanan meja';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('self_order') && $user->role->hasFullDashboard() && $user->hasPermission('order.view_all');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Outlet pilihan sidebar ("Semua outlet" = semua outlet yang dipegang user), ADR 0010.
     *
     * @return Builder<SelfOrder>
     */
    public static function getEloquentQuery(): Builder
    {
        return CurrentOutlet::scope(SelfOrder::query());
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'diningTable:id,name,area', 'outlet:id,name', 'member:id,name', 'handler:id,name', 'order:id,order_number',
            ]))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y')->sortable()
                    ->description(fn (SelfOrder $record): string => $record->created_at->setTimezone(CurrentOutlet::timezone())->format('H.i')),
                TextColumn::make('diningTable.name')->label('Meja')->weight('medium')
                    ->description(fn (SelfOrder $record): ?string => $record->diningTable?->area),
                // Tampil saat "Semua outlet" dipilih dan bisnis punya lebih dari satu outlet (ADR 0010)
                TextColumn::make('outlet.name')->label('Outlet')->badge()->color('gray')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && Outlet::query()->count() > 1),
                TextColumn::make('customer_name')->label('Pelanggan')->searchable()->sortable()
                    ->description(fn (SelfOrder $record): ?string => $record->member !== null ? 'Member · '.$record->member->name : null),
                TextColumn::make('items_count')->label('Item')->alignEnd()
                    ->state(fn (SelfOrder $record): int => $record->itemCount())
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.')),
                MoneyColumn::make('estimated_total')->label('Perkiraan total')->sortable(),
                TextColumn::make('status')->label('Status')->badge()->sortable()
                    ->description(fn (SelfOrder $record): ?string => match ($record->status) {
                        SelfOrderStatus::Accepted => $record->order?->order_number,
                        SelfOrderStatus::Rejected => filled($record->reject_reason) ? mb_strimwidth((string) $record->reject_reason, 0, 40, '…') : null,
                        default => null,
                    }),
                TextColumn::make('handler.name')->label('Ditangani')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (SelfOrder $record): ?string => $record->handled_at?->setTimezone(CurrentOutlet::timezone())->format('H.i')),
            ])
            ->filters([
                DateRangeFilter::make('created_at'),
                SelectFilter::make('dining_table_id')->label('Meja')->searchable()
                    ->options(fn (): array => DiningTable::query()->whereIn('outlet_id', CurrentOutlet::ids())->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('handled_by')->label('Ditangani')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->persistFiltersInSession()
            ->recordAction('detail')
            ->recordActions([
                Action::make('detail')->label('Detail')->icon(Heroicon::OutlinedEye)->iconButton()->tooltip('Detail pesanan')
                    ->modalHeading(fn (SelfOrder $record): string => 'Pesanan meja · '.($record->diningTable->name ?? '—'))
                    ->modalDescription(fn (SelfOrder $record): string => $record->customer_name)
                    ->modalWidth('2xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (SelfOrder $record) => view('filament.dashboard.orders.table-order', [
                        'selfOrder' => $record,
                        'lines' => self::lines($record),
                        'orderUrl' => $record->order_id !== null && OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null,
                    ])),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedDevicePhoneMobile, 'pesanan meja',
            'Pesanan pelanggan dari QR meja akan muncul di sini, termasuk yang ditolak atau kedaluwarsa');
    }

    /**
     * Item pesanan dengan nama produk & opsi saat ini (pesanan menyimpan ID saja; snapshot harga ada di order).
     *
     * @return list<array{name: string, qty: int, options: list<string>, notes: string|null}>
     */
    public static function lines(SelfOrder $record): array
    {
        $productIds = array_column($record->items, 'product_id');
        $optionIds = array_merge([], ...array_column($record->items, 'option_ids'));
        $products = Product::query()->withTrashed()->whereIn('id', $productIds)->pluck('name', 'id');
        $options = Option::query()->withTrashed()->whereIn('id', $optionIds)->pluck('name', 'id');

        return array_map(fn (array $item): array => [
            'name' => (string) ($products[$item['product_id']] ?? 'Produk dihapus'),
            'qty' => (int) $item['qty'],
            'options' => array_values(array_filter(array_map(fn (string $id): ?string => $options[$id] ?? null, $item['option_ids']))),
            'notes' => $item['notes'] ?? null,
        ], $record->items);
    }

    public static function getPages(): array
    {
        return ['index' => ListSelfOrders::route('/')];
    }
}
