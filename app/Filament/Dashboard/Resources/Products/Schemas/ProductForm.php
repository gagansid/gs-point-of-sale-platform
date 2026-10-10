<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Products\Schemas;

use App\Filament\Shared\Forms\MoneyInput;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Support\CurrentOutlet;
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
                            ->description('Nama, kategori, dan harga yang tampil di aplikasi kasir')
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
                                    ->placeholder('KOP-001')
                                    ->helperText('Kode internal untuk stok & laporan. Opsional')
                                    ->unique('products', 'sku', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => self::tenantUnique($rule)),
                                TextInput::make('barcode')->label('Barcode')->maxLength(50)
                                    ->placeholder('8991234567890')
                                    ->helperText('Angka di bawah garis barcode kemasan, untuk dipindai kasir. Kosongkan untuk menu racikan')
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
                            // Stok awal, stok saat ini, dan stok minimum milik outlet aktif (ADR 0010)
                            ->description(fn (): string => 'Lacak stok untuk mengurangi otomatis saat terjual dan peringatan stok menipis'
                                .(Outlet::query()->count() > 1 ? '. Angka stok untuk outlet '.CurrentOutlet::getOrFail()->name : ''))
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
                            ->description('Tampil di aplikasi kasir')
                            ->schema([
                                FileUpload::make('image_path')
                                    ->hiddenLabel()
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['1:1'])
                                    ->imageCropAspectRatio('1:1')
                                    // Panel persegi: area seret-lepas & pratinjau sama ukurannya
                                    ->panelAspectRatio('1:1')
                                    ->placeholder(self::uploadPlaceholder())
                                    ->helperText('JPG, PNG, atau WEBP · maks. 1 MB · dipotong persegi')
                                    ->disk('public')
                                    ->directory(fn (): string => 'products/'.TenantContext::idOrFail())
                                    ->maxSize(1024)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']),
                            ]),
                        Section::make('Status')
                            ->description('Atur tampil atau tidaknya di kasir')
                            ->schema([
                                Toggle::make('is_active')->label('Aktif')->default(true)
                                    ->helperText('Produk nonaktif tidak tampil di aplikasi kasir'),
                                Toggle::make('is_favorite')->label('Favorit')
                                    ->helperText('Tampil di tab Favorit aplikasi kasir'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Isi area seret-lepas FilePond (labelIdle, HTML statis tanpa input pengguna):
     * ikon gambar + "Pilih gambar atau seret ke sini".
     */
    private static function uploadPlaceholder(): string
    {
        return '<span class="gs-upload-idle">'
            .'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Zm10.5-11.25h.008v.008h-.008V9.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>'
            .'<span><span class="filepond--label-action">Pilih gambar</span> atau seret ke sini</span>'
            .'</span>';
    }

    /** SKU/barcode unik di antara produk tenant aktif yang belum dihapus. */
    private static function tenantUnique(Unique $rule): Unique
    {
        return $rule->where('tenant_id', TenantContext::id())->whereNull('deleted_at');
    }
}
