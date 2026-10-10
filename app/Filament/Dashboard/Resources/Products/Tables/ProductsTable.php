<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Tables;

use App\Actions\Product\DeleteProduct;
use App\Filament\Dashboard\Resources\Products\Actions\ProductActions;
use App\Filament\Shared\Actions\BulkDeleteAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Category;
use App\Models\Product;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Daftar produk (table.md): stok ≤ 0 ditandai merah, status habis sebagai badge.
 */
final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                // Bintang favorit: klik untuk menandai/melepas (tampil di tab "Favorit" aplikasi kasir)
                IconColumn::make('is_favorite')
                    ->label('')
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::Star : Heroicon::OutlinedStar)
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->tooltip(fn (Product $record): string => $record->is_favorite ? 'Lepas dari favorit' : 'Jadikan favorit')
                    ->action(ProductActions::toggleFavorite())
                    ->extraCellAttributes(['class' => 'gs-ta-cell-favorite']),
                ImageColumn::make('image_path')->label('Gambar')->disk('public')->square()->imageSize(24)
                    // Disembunyikan bawaan: kolom kosong membuat jarak checkbox ↔ nama terlalu jauh
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label('Nama produk')
                    ->description(fn (Product $record): ?string => $record->sku)
                    ->weight('medium')
                    ->searchable(['name', 'sku', 'barcode'])
                    ->sortable(),
                TextColumn::make('category.name')->label('Kategori')->placeholder('—')->sortable()->toggleable(),
                MoneyColumn::make('price')->label('Harga')->sortable(),
                TextColumn::make('stock_qty')
                    ->label('Stok')
                    ->state(fn (Product $record): ?int => $record->track_stock ? $record->stock_qty : null)
                    ->placeholder('—')
                    ->numeric(locale: 'id')
                    ->alignEnd()
                    ->sortable()
                    // Merah = habis (≤ 0), kuning = menipis (≤ stok minimum)
                    ->color(fn (Product $record): ?string => match (true) {
                        $record->isOutOfStock() => 'danger',
                        $record->isLowStock() => 'warning',
                        default => null,
                    })
                    ->icon(fn (Product $record): ?Heroicon => $record->isLowStock() ? Heroicon::OutlinedExclamationTriangle : null)
                    ->iconPosition('after')
                    ->tooltip(fn (Product $record): ?string => $record->isLowStock() ? "Stok menipis (minimum {$record->min_stock})" : null)
                    ->weight(fn (Product $record) => $record->isOutOfStock() || $record->isLowStock() ? 'semibold' : null),
                TextColumn::make('is_available')
                    ->label('Ketersediaan')
                    ->badge()
                    ->sortable()
                    // Tidak dijual di outlet aktif (pemilih sidebar) (ADR 0011) didahulukan dari habis/tersedia
                    ->formatStateUsing(fn (bool $state, Product $record): string => match (true) {
                        ! $record->is_listed => 'Tidak dijual',
                        $state => 'Tersedia',
                        default => 'Habis',
                    })
                    ->color(fn (bool $state, Product $record): string => match (true) {
                        ! $record->is_listed => 'gray',
                        $state => 'success',
                        default => 'danger',
                    }),
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->sortable()->toggleable(),
                // Audit: tersembunyi bawaan, tampilkan lewat pilih kolom
                ...AuditColumns::make(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Kategori')
                    ->multiple()
                    ->options(fn (): array => Category::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('option_groups')
                    ->label('Grup opsi')
                    ->multiple()
                    ->preload()
                    ->relationship('optionGroups', 'name'),
                TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
                TernaryFilter::make('is_available')->label('Ketersediaan')->trueLabel('Tersedia')->falseLabel('Habis')
                    ->queries(
                        true: fn (Builder $query) => $query->scopes(['availableAt' => [CurrentOutlet::getOrFail()->id, true]]),
                        false: fn (Builder $query) => $query->scopes(['availableAt' => [CurrentOutlet::getOrFail()->id, false]]),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('is_listed')->label('Dijual di outlet ini')->trueLabel('Dijual')->falseLabel('Tidak dijual')
                    ->queries(
                        true: fn (Builder $query) => $query->scopes(['listedAt' => [CurrentOutlet::getOrFail()->id]]),
                        false: fn (Builder $query) => $query->whereHas('stocks', fn (Builder $stock) => $stock
                            ->where('outlet_id', CurrentOutlet::getOrFail()->id)->where('is_listed', false)),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('is_favorite')->label('Favorit')->trueLabel('Favorit')->falseLabel('Bukan favorit'),
                TernaryFilter::make('track_stock')->label('Lacak stok')->trueLabel('Dilacak')->falseLabel('Tidak dilacak'),
                self::priceRangeFilter(),
                Filter::make('out_of_stock')
                    ->label('Stok habis (≤ 0)')
                    ->query(fn (Builder $query) => $query->scopes(['outOfStock' => [CurrentOutlet::getOrFail()->id]])),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah'),
                ActionGroup::make([
                    ProductActions::adjustStock(),
                    ProductActions::markSoldOut(),
                    ProductActions::markAvailable(),
                    ProductActions::delete(),
                ])->tooltip('Aksi lain'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ProductActions::bulkSetAvailability(false),
                    ProductActions::bulkSetAvailability(true),
                    BulkDeleteAction::make(
                        Product::class,
                        fn (Product $product) => app(DeleteProduct::class)->handle($product),
                        'produk',
                        'Produk hilang dari aplikasi kasir. Riwayat transaksi tetap utuh.',
                        'product.manage',
                    ),
                ])->label('Aksi massal'),
            ])
            // Urutan bawaan = urutan tampil di kasir; Filament menambahkan id sebagai pemecah seri
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort_order')->orderBy('name'))
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action
                ->tooltip($isReordering ? 'Selesai mengatur urutan' : 'Atur urutan tampil di kasir'));

        return TableEmptyState::apply($table, Heroicon::OutlinedCube, 'produk', 'Tambahkan produk pertama lewat tombol + Tambah');
    }

    /**
     * Rentang harga jual (Rp). Nilai kosong diabaikan; indikator menampilkan nominal terformat.
     */
    private static function priceRangeFilter(): Filter
    {
        return Filter::make('price_range')
            ->schema([
                TextInput::make('price_min')->label('Harga dari')->prefix('Rp')->integer()->minValue(0),
                TextInput::make('price_max')->label('Harga sampai')->prefix('Rp')->integer()->minValue(0),
            ])
            ->columns(2)
            ->columnSpan(['default' => 1, 'sm' => 2])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(filled($data['price_min'] ?? null), fn (Builder $q) => $q->where('price', '>=', (int) $data['price_min']))
                ->when(filled($data['price_max'] ?? null), fn (Builder $q) => $q->where('price', '<=', (int) $data['price_max'])))
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if (filled($data['price_min'] ?? null)) {
                    $indicators[] = Indicator::make('Harga ≥ Rp'.Number::format((int) $data['price_min'], locale: 'id'))->removeField('price_min');
                }

                if (filled($data['price_max'] ?? null)) {
                    $indicators[] = Indicator::make('Harga ≤ Rp'.Number::format((int) $data['price_max'], locale: 'id'))->removeField('price_max');
                }

                return $indicators;
            });
    }
}
