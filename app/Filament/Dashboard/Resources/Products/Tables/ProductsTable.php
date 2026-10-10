<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Tables;

use App\Filament\Dashboard\Resources\Products\Actions\ProductActions;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar produk (table.md): stok ≤ 0 ditandai merah, status habis sebagai badge.
 */
final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->square()->imageSize(24),
                TextColumn::make('name')
                    ->label('Nama produk')
                    ->description(fn (Product $record): ?string => $record->sku)
                    ->weight('medium')
                    ->searchable(['name', 'sku', 'barcode'])
                    ->sortable(),
                TextColumn::make('category.name')->label('Kategori')->placeholder('—')->toggleable(),
                MoneyColumn::make('price')->label('Harga')->sortable(),
                TextColumn::make('stock_qty')
                    ->label('Stok')
                    ->state(fn (Product $record): ?int => $record->track_stock ? $record->stock_qty : null)
                    ->placeholder('—')
                    ->numeric(locale: 'id')
                    ->alignEnd()
                    ->color(fn (Product $record): ?string => $record->isOutOfStock() ? 'danger' : null)
                    ->weight(fn (Product $record) => $record->isOutOfStock() ? 'semibold' : null),
                TextColumn::make('is_available')
                    ->label('Ketersediaan')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Tersedia' : 'Habis')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Kategori')
                    ->options(fn (): array => Category::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
                Filter::make('out_of_stock')
                    ->label('Stok habis (≤ 0)')
                    ->query(fn (Builder $query) => $query->where('track_stock', true)->where('stock_qty', '<=', 0)),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah'),
                ActionGroup::make([
                    ProductActions::adjustStock(),
                    ProductActions::toggleAvailability(),
                    ProductActions::delete(),
                ])->tooltip('Aksi lain'),
            ])
            ->defaultSort('name')
            ->paginated([10, 20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->persistFiltersInSession()
            ->emptyStateIcon(Heroicon::OutlinedCube)
            ->emptyStateHeading('Belum ada produk')
            ->emptyStateDescription('Tambahkan produk pertama untuk mulai berjualan')
            ->emptyStateActions([
                CreateAction::make()->label('Tambah produk')->icon(Heroicon::OutlinedPlus),
            ]);
    }
}
