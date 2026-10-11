<?php

declare(strict_types=1);

use App\Actions\Product\ImportProducts;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Import produk dari Excel/CSV (SPEC Q59). */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang']);
    TenantContext::set($this->outlet->tenant_id);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner, $this->outlet->id);
});

function importRows(array $rows): array
{
    return app(ImportProducts::class)->handle($rows, test()->outlet, test()->owner);
}

function importRow(array $override = []): array
{
    return ['nama' => 'Croissant', 'kategori' => 'Makanan', 'harga' => 24000, 'harga_modal' => 12000, 'sku' => 'MKN-001',
        'barcode' => null, 'lacak_stok' => 'ya', 'stok_awal' => 20, 'aktif' => 'ya', ...$override];
}

/** @return array<string, list<string>> */
function importErrors(array $rows): array
{
    try {
        importRows($rows);
    } catch (BusinessException $e) {
        return $e->details;
    }

    throw new RuntimeException('Import seharusnya gagal');
}

it('membuat produk & kategori baru, stok awal di outlet aktif', function () {
    $result = importRows([
        importRow(),
        importRow(['nama' => 'Es Kopi Susu', 'kategori' => 'kopi', 'harga' => 'Rp 22.000', 'harga_modal' => '9.000,50', 'sku' => 'KOPI-001', 'lacak_stok' => 'tidak', 'stok_awal' => null]),
        importRow(['nama' => 'Kopi Hitam', 'kategori' => 'KOPI', 'harga' => '18000', 'harga_modal' => null, 'sku' => null, 'lacak_stok' => null, 'stok_awal' => null, 'aktif' => 'tidak']),
    ]);

    expect($result)->toBe(['created' => 3, 'updated' => 0])
        // "kopi" & "KOPI" = satu kategori
        ->and(Category::query()->pluck('name')->sort()->values()->all())->toBe(['Makanan', 'kopi']);

    $croissant = Product::query()->where('sku', 'MKN-001')->sole();
    $coffee = Product::query()->where('sku', 'KOPI-001')->sole();
    expect($croissant->price)->toBe('24000.00')
        ->and($croissant->track_stock)->toBeTrue()
        ->and(stockOf($croissant, $this->outlet->id)->stock_qty)->toBe(20)
        ->and($coffee->price)->toBe('22000.00')
        ->and($coffee->cost_price)->toBe('9000.50')
        ->and(Product::query()->where('name', 'Kopi Hitam')->sole()->is_active)->toBeFalse();
});

it('SKU yang sudah ada diperbarui: harga berubah, grup opsi, stok & stok minimum tetap', function () {
    $group = OptionGroup::factory()->create();
    $product = Product::factory()->tracked(7)->create(['sku' => 'MKN-001', 'name' => 'Croissant Lama', 'price' => '20000.00', 'is_favorite' => true]);
    $product->optionGroups()->attach($group->id, ['sort_order' => 0]);
    ProductStock::query()->where('product_id', $product->id)->update(['min_stock' => 3]);

    expect(importRows([importRow(['sku' => 'mkn-001', 'stok_awal' => 99])]))->toBe(['created' => 0, 'updated' => 1]);

    $product->refresh();
    expect($product->name)->toBe('Croissant')
        ->and($product->price)->toBe('24000.00')
        ->and($product->is_favorite)->toBeTrue()
        ->and($product->optionGroups()->pluck('option_groups.id')->all())->toBe([$group->id])
        ->and(stockOf($product, $this->outlet->id)->stock_qty)->toBe(7)
        ->and(stockOf($product, $this->outlet->id)->min_stock)->toBe(3)
        ->and(Product::query()->count())->toBe(1);
});

it('satu baris salah = tidak ada yang disimpan; error per nomor baris', function () {
    Product::factory()->create(['barcode' => '8991234567890']);

    $errors = importErrors([
        importRow(),                                                   // baris 2: valid
        importRow(['nama' => null, 'sku' => 'A-1']),                    // baris 3
        importRow(['harga' => 'gratis', 'sku' => 'A-2']),               // baris 4
        importRow(['sku' => 'MKN-001']),                               // baris 5: SKU ganda di file
        importRow(['sku' => 'A-3', 'barcode' => '8991234567890']),     // baris 6: barcode dipakai
        importRow(['sku' => 'A-4', 'lacak_stok' => 'mungkin']),         // baris 7
    ]);

    expect(array_keys($errors))->toBe(['baris 3', 'baris 4', 'baris 5', 'baris 6', 'baris 7'])
        ->and($errors['baris 5'][0])->toContain('dipakai lebih dari sekali')
        ->and($errors['baris 6'][0])->toContain('sudah dipakai produk lain')
        ->and($errors['baris 7'][0])->toContain('"ya" atau "tidak"')
        ->and(Product::query()->where('sku', 'MKN-001')->exists())->toBeFalse()
        ->and(Category::query()->count())->toBe(0);
});

it('teks diawali = + - @ ditolak (formula injection)', function (string $field, string $value) {
    $errors = importErrors([importRow([$field => $value])]);

    expect($errors['baris 2'][0])->toContain('tidak boleh diawali');
})->with([
    'nama' => ['nama', '=HYPERLINK("http://evil.test","klik")'],
    'kategori' => ['kategori', '+cmd|calc'],
    'sku' => ['sku', '@SUM(A1)'],
]);

it('file kosong & lebih dari batas baris ditolak', function () {
    expect(fn () => importRows([['nama' => null, 'harga' => null]]))->toThrow(BusinessException::class, 'File tidak berisi data produk');

    $rows = array_map(fn (int $i): array => importRow(['sku' => "S-{$i}"]), range(1, ImportProducts::MAX_ROWS + 1));
    expect(fn () => importRows($rows))->toThrow(BusinessException::class, 'Maksimal');
});

it('isolasi tenant: SKU & kategori tenant lain tidak dipakai', function () {
    TenantContext::forget();
    $other = Outlet::factory()->create();
    $foreign = TenantContext::run($other->tenant_id, function (): Product {
        Category::factory()->create(['name' => 'Makanan']);

        return Product::factory()->create(['sku' => 'MKN-001', 'price' => '1.00']);
    });
    TenantContext::set($this->outlet->tenant_id);

    expect(importRows([importRow()]))->toBe(['created' => 1, 'updated' => 0])
        ->and(Product::allTenants()->whereKey($foreign->id)->value('price'))->toBe('1.00')
        ->and(Product::query()->sole()->category?->tenant_id)->toBe($this->outlet->tenant_id);
});

it('lewat halaman Produk: unggah CSV → produk dibuat; file sementara dihapus', function () {
    $csv = "nama,kategori,harga,harga_modal,sku,barcode,lacak_stok,stok_awal,aktif\nCroissant,Makanan,24000,12000,MKN-001,,ya,20,ya\nEs Teh,Minuman,8000,,MNM-001,,tidak,,ya\n";

    Livewire::test(ListProducts::class)
        ->callAction('import', ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)])
        ->assertHasNoActionErrors()
        ->assertNotified('Import selesai');

    expect(Product::query()->pluck('name')->sort()->values()->all())->toBe(['Croissant', 'Es Teh'])
        ->and(Storage::disk('local')->files('imports'))->toBe([]);
});

it('lewat halaman Produk: file salah → notifikasi error per baris, tidak ada yang disimpan', function () {
    $csv = "nama,kategori,harga\nCroissant,Makanan,24000\n,Makanan,abc\n";

    Livewire::test(ListProducts::class)
        ->callAction('import', ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)])
        ->assertNotified('1 baris belum benar. Perbaiki lalu import ulang');

    expect(Product::query()->count())->toBe(0);
});

it('template bisa diunduh; supervisor & kasir tidak melihat tombol Import', function () {
    Livewire::test(ListProducts::class)
        ->assertActionVisible('import')
        ->mountAction('import')
        ->callAction(TestAction::make('template')->arguments([]))
        ->assertFileDownloaded('template-import-produk.xlsx');

    $this->actingAs(User::factory()->supervisor()->forOutlet($this->outlet)->create());
    expect(ProductResource::canCreate())->toBeFalse();
});
