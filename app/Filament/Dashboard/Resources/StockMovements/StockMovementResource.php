<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Stok → Riwayat stok (Q14, ADR 0015; izin stock.adjust): kartu stok per produk & outlet — setiap penjualan, void,
 * retur, stok masuk, opname, transfer, dan penyesuaian beserta stok akhirnya. Hanya baca; `stock_movements` append-only.
 */
final class StockMovementResource extends DashboardResource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = StockMovement::class;

    protected static ?string $slug = 'stock/movements';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?string $navigationLabel = 'Riwayat stok';

    protected static ?string $modelLabel = 'riwayat stok';

    protected static ?string $pluralModelLabel = 'riwayat stok';

    protected static ?int $navigationSort = 4;

    protected static bool $isGloballySearchable = false;

    protected static function canUseMenu(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('stock') && $user->hasPermission('stock.adjust');
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
     * @return Builder<StockMovement>
     */
    public static function getEloquentQuery(): Builder
    {
        return CurrentOutlet::scope(StockMovement::query());
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                // Produk yang sudah dihapus tetap tampil namanya di riwayat
                'product' => fn (Relation $q): Relation => $q->withoutGlobalScope(SoftDeletingScope::class)->select(['id', 'name', 'sku', 'unit']),
                'outlet:id,name',
                'user:id,name',
            ]))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y')->sortable()
                    ->description(fn (StockMovement $record): string => $record->created_at->copy()->setTimezone(CurrentOutlet::timezone())->format('H.i')),
                TextColumn::make('product.name')->label('Produk')->weight('medium')
                    ->description(fn (StockMovement $record): ?string => $record->product?->sku)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn('product_id', Product::query()->withTrashed()
                        ->where(fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'))->select('id'))),
                // Tampil saat "Semua outlet" dipilih dan bisnis punya lebih dari satu outlet (ADR 0010)
                TextColumn::make('outlet.name')->label('Outlet')->badge()->color('gray')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && Outlet::query()->count() > 1),
                TextColumn::make('type')->label('Jenis')->badge()->sortable(),
                TextColumn::make('qty_change')->label('Perubahan')->alignEnd()->sortable()
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').number_format($state, 0, ',', '.'))
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->weight('medium'),
                TextColumn::make('qty_after')->label('Stok akhir')->alignEnd()->sortable()
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->color(fn (int $state): ?string => $state <= 0 ? 'danger' : null),
                TextColumn::make('reference')->label('Dokumen')
                    ->state(fn (StockMovement $record, ListStockMovements $livewire): string => self::reference($record, $livewire)['label']
                        ?? ($record->type === StockMovementType::Adjustment ? 'Penyesuaian manual' : '—'))
                    ->url(fn (StockMovement $record, ListStockMovements $livewire): ?string => self::reference($record, $livewire)['url'] ?? null)
                    ->color(fn (StockMovement $record, ListStockMovements $livewire): ?string => (self::reference($record, $livewire)['url'] ?? null) !== null ? 'primary' : null)
                    ->description(fn (StockMovement $record): ?string => filled($record->reason) ? mb_strimwidth((string) $record->reason, 0, 60, '…') : null)
                    ->tooltip(fn (StockMovement $record): ?string => filled($record->reason) && mb_strlen((string) $record->reason) > 60 ? $record->reason : null),
                TextColumn::make('user.name')->label('Oleh')->placeholder('Sistem')->visibleFrom('lg')->toggleable(),
            ])
            ->filters([
                DateRangeFilter::make('created_at'),
                SelectFilter::make('product_id')->label('Produk')->searchable()
                    ->options(fn (): array => Product::query()->where('track_stock', true)->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('type')->label('Jenis')->multiple()->options(StockMovementType::class),
                SelectFilter::make('user_id')->label('Oleh')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->persistFiltersInSession();

        return TableEmptyState::apply($table, Heroicon::OutlinedQueueList, 'riwayat stok',
            'Setiap penjualan, stok masuk, opname, transfer, dan penyesuaian produk lacak stok tercatat otomatis di sini');
    }

    /** @return array{label: string, url: string|null}|null */
    private static function reference(StockMovement $record, ListStockMovements $livewire): ?array
    {
        return StockMovementReferences::for($record, collect($livewire->getTableRecords()->items()));
    }

    /** Tautan "Riwayat stok" satu produk (dari daftar Produk): filter produk, tanpa batas tanggal. */
    public static function urlForProduct(Product $product): string
    {
        return self::getUrl('index', ['product' => $product->id]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
