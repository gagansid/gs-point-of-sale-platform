<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Schemas;

use App\Filament\Shared\Forms\MoneyInput;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Support\TenantContext;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Form produk (form.md): kolom kiri data utama, kolom kanan gambar & status.
 */
final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Informasi produk')
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')->label('Nama produk')->placeholder('Es Kopi Susu')
                                    ->required()->maxLength(150)->autofocus()->columnSpanFull(),
                                Select::make('category_id')->label('Kategori')
                                    // Query Eloquent → otomatis hanya kategori tenant aktif
                                    ->options(fn (): array => Category::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->placeholder('Tanpa kategori'),
                                MoneyInput::make('price')->label('Harga jual')->required(),
                                TextInput::make('sku')->label('SKU')->maxLength(50)
                                    ->unique('products', 'sku', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => self::tenantUnique($rule)),
                                TextInput::make('barcode')->label('Barcode')->maxLength(50)
                                    ->regex('/^[A-Za-z0-9-]+$/')
                                    ->unique('products', 'barcode', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => self::tenantUnique($rule)),
                                MoneyInput::make('cost_price')->label('Harga modal')
                                    ->helperText('Tidak ditampilkan ke kasir'),
                            ]),

                        Section::make('Grup opsi')
                            ->description('Urutan pilihan = urutan tampil di aplikasi kasir')
                            ->schema([
                                Select::make('option_group_ids')
                                    ->hiddenLabel()
                                    ->multiple()
                                    ->options(fn (): array => OptionGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->maxItems(20)
                                    ->placeholder('Pilih grup opsi, mis. Ukuran, Gula'),
                            ]),

                        Section::make('Stok')
                            ->columns(2)
                            ->schema([
                                Toggle::make('track_stock')->label('Lacak stok')
                                    ->helperText('Stok berkurang otomatis saat terjual. Boleh minus')
                                    ->live(),
                                TextInput::make('stock_qty')->label('Stok awal')
                                    ->integer()->minValue(-1000000)->maxValue(1000000)->default(0)
                                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && (bool) $get('track_stock')),
                                TextInput::make('current_stock')->label('Stok saat ini')
                                    ->disabled()->dehydrated(false)
                                    ->helperText('Ubah lewat aksi Sesuaikan stok di daftar produk')
                                    ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' && (bool) $get('track_stock')),
                                TextInput::make('min_stock')->label('Stok minimum')
                                    ->integer()->minValue(0)->maxValue(1000000)
                                    ->placeholder('Tanpa peringatan')
                                    ->helperText('Stok ≤ angka ini ditandai "stok menipis"')
                                    ->visible(fn (Get $get): bool => (bool) $get('track_stock')),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Gambar')
                            ->schema([
                                FileUpload::make('image_path')
                                    ->hiddenLabel()
                                    ->image()
                                    ->imageEditor()
                                    ->imageCropAspectRatio('1:1')
                                    ->disk('public')
                                    ->directory(fn (): string => 'products/'.TenantContext::idOrFail())
                                    ->maxSize(1024)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']),
                            ]),
                        Section::make('Status')
                            ->schema([
                                Toggle::make('is_active')->label('Aktif')->default(true)
                                    ->helperText('Produk nonaktif tidak tampil di aplikasi kasir'),
                                Toggle::make('is_favorite')->label('Favorit')
                                    ->helperText('Tampil di tab Favorit aplikasi kasir'),
                            ]),
                    ]),
            ]);
    }

    /** SKU/barcode unik di antara produk tenant aktif yang belum dihapus. */
    private static function tenantUnique(Unique $rule): Unique
    {
        return $rule->where('tenant_id', TenantContext::id())->whereNull('deleted_at');
    }
}
