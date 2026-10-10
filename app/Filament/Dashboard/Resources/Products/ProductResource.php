<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products;

use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Filament\Dashboard\Resources\Products\Pages\EditProduct;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Dashboard\Resources\Products\Schemas\ProductForm;
use App\Filament\Dashboard\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Support\CurrentOutlet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Menu Katalog → Produk. Simpan lewat Action SaveProduct; stok lewat aksi Sesuaikan stok.
 * Katalog & harga per bisnis; stok dan ketersediaan milik outlet aktif di pemilih outlet sidebar (ADR 0010).
 */
final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Produk';

    protected static ?string $modelLabel = 'produk';

    protected static ?string $pluralModelLabel = 'produk';

    protected static ?string $recordTitleAttribute = 'name';

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return Product::query()->atOutlet(CurrentOutlet::getOrFail()->id);
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
