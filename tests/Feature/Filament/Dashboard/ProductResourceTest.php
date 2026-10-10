<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Filament\Dashboard\Resources\Products\Pages\EditProduct;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    // Request Livewire di test tidak melewati middleware panel: set tenant seperti SetDashboardTenant
    TenantContext::set($this->owner->tenant_id);
});

it('hanya menampilkan produk tenant sendiri', function () {
    $mine = Product::factory()->count(2)->create();
    $foreign = TenantContext::run(Tenant::factory()->create()->id, fn () => Product::factory()->create());

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('menambah produk lewat form dengan harga bermask & grup opsi', function () {
    $category = Category::factory()->create();
    [$size, $sugar] = [OptionGroup::factory()->create(), OptionGroup::factory()->create()];

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Es Kopi Susu',
            'category_id' => $category->id,
            'price' => '22.000',
            'cost_price' => '8.800',
            'option_group_ids' => [$sugar->id, $size->id],
            'track_stock' => true,
            'stock_qty' => 12,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->sole();
    expect($product->price)->toBe('22000.00')
        ->and($product->cost_price)->toBe('8800.00')
        ->and($product->stock_qty)->toBe(12)
        ->and($product->optionGroups()->pluck('option_groups.id')->all())->toBe([$sugar->id, $size->id])
        ->and(StockMovement::query()->sole()->reason)->toBe('Stok awal');
});

it('regresi: membuka & menyimpan form tanpa perubahan tidak mengubah harga', function () {
    $product = Product::factory()->create(['price' => '22000.00', 'cost_price' => '8800.00']);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaStateSet(['price' => '22000', 'cost_price' => '8800'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->price)->toBe('22000.00')
        ->and($product->cost_price)->toBe('8800.00');
});

it('validasi: SKU dobel di tenant sendiri ditolak', function () {
    Product::factory()->create(['sku' => 'KOP-1']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Baru', 'price' => '10.000', 'sku' => 'KOP-1'])
        ->call('create')
        ->assertHasFormErrors(['sku' => 'unique']);
});

it('menyesuaikan stok dari daftar produk', function () {
    $product = Product::factory()->tracked(10)->create();

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), ['qty_change' => -4, 'reason' => 'Rusak'])
        ->assertHasNoFormErrors();

    expect($product->refresh()->stock_qty)->toBe(6)
        ->and(StockMovement::query()->sole()->user_id)->toBe($this->owner->id);
});

it('menandai produk habis', function () {
    $product = Product::factory()->create();

    Livewire::test(ListProducts::class)->callAction(TestAction::make('markSoldOut')->table($product));

    expect($product->refresh()->is_available)->toBeFalse();
});

it('tandai habis memakai modal konfirmasi; tandai tersedia langsung tanpa modal', function () {
    $available = Product::factory()->create(['name' => 'Es Teh', 'is_available' => true]);
    $soldOut = Product::factory()->create(['name' => 'Caffe Latte', 'is_available' => false]);

    $action = Livewire::test(ListProducts::class)
        ->assertActionVisible(TestAction::make('markSoldOut')->table($available))
        ->assertActionHidden(TestAction::make('markAvailable')->table($available))
        ->mountAction(TestAction::make('markSoldOut')->table($available))
        ->instance()->getMountedAction();

    expect($action?->isConfirmationRequired())->toBeTrue()
        ->and((string) $action?->getModalDescription())->toContain('Tandai <strong>Es Teh</strong> sebagai habis?')
        ->and($available->refresh()->is_available)->toBeTrue();

    // Regresi: produk habis tidak boleh memunculkan modal "Tandai habis"
    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('markSoldOut')->table($soldOut))
        ->callAction(TestAction::make('markAvailable')->table($soldOut));

    expect($soldOut->refresh()->is_available)->toBeTrue();
});

it('form edit tidak bisa membuka produk tenant lain', function () {
    $foreign = TenantContext::run(
        Tenant::factory()->create()->id,
        fn () => Product::factory()->create(),
    );

    $this->get("/dashboard/products/{$foreign->id}/edit")->assertNotFound();
});

it('filter tidak ditulis ke URL; tetap tersimpan di session', function () {
    $property = new ReflectionProperty(ListProducts::class, 'tableFilters');

    expect($property->getDeclaringClass()->getName())->toBe(ListProducts::class)
        ->and($property->getAttributes(Livewire\Attributes\Url::class))->toBeEmpty();
});

it('memfilter berdasarkan ketersediaan, lacak stok, dan rentang harga', function () {
    $cheap = Product::factory()->create(['price' => 10000, 'is_available' => true, 'track_stock' => false]);
    $soldOut = Product::factory()->create(['price' => 25000, 'is_available' => false, 'track_stock' => true]);
    $pricey = Product::factory()->create(['price' => 50000, 'is_available' => true, 'track_stock' => true]);

    Livewire::test(ListProducts::class)
        ->filterTable('is_available', false)
        ->assertCanSeeTableRecords([$soldOut])
        ->assertCanNotSeeTableRecords([$cheap, $pricey])
        ->resetTableFilters()
        ->filterTable('track_stock', true)
        ->assertCanSeeTableRecords([$soldOut, $pricey])
        ->assertCanNotSeeTableRecords([$cheap])
        ->resetTableFilters()
        ->filterTable('price_range', ['price_min' => 20000, 'price_max' => 30000])
        ->assertCanSeeTableRecords([$soldOut])
        ->assertCanNotSeeTableRecords([$cheap, $pricey]);
});

it('mengurutkan berdasarkan harga naik dan turun', function () {
    $records = collect([30000, 10000, 20000])->map(fn (int $price) => Product::factory()->create(['price' => $price]));

    Livewire::test(ListProducts::class)
        ->sortTable('price')
        ->assertCanSeeTableRecords($records->sortBy('price'), inOrder: true)
        ->sortTable('price', 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc('price'), inOrder: true);
});

it('aksi massal: tandai habis lalu hapus hanya produk terpilih', function () {
    [$a, $b, $c] = Product::factory()->count(3)->create(['is_available' => true])->all();

    Livewire::test(ListProducts::class)
        ->selectTableRecords([$a, $b])
        ->callAction(TestAction::make('bulkMarkSoldOut')->table()->bulk())
        ->assertHasNoActionErrors();

    expect($a->refresh()->is_available)->toBeFalse()
        ->and($b->refresh()->is_available)->toBeFalse()
        ->and($c->refresh()->is_available)->toBeTrue();

    Livewire::test(ListProducts::class)
        ->selectTableRecords([$a, $b])
        ->callAction(TestAction::make('bulkDelete')->table()->bulk());

    expect(Product::query()->pluck('id')->all())->toBe([$c->id]);
});

it('aksi massal tidak bisa menyentuh produk tenant lain', function () {
    $mine = Product::factory()->create(['is_available' => true]);
    $foreign = TenantContext::run(Tenant::factory()->create()->id, fn () => Product::factory()->create(['is_available' => true]));

    Livewire::test(ListProducts::class)
        ->selectTableRecords([$mine->id, $foreign->id])
        ->callAction(TestAction::make('bulkMarkSoldOut')->table()->bulk());

    expect($mine->refresh()->is_available)->toBeFalse()
        ->and(TenantContext::run($foreign->tenant_id, fn () => $foreign->refresh()->is_available))->toBeTrue();
});

it('empty state membedakan belum ada produk dan hasil filter kosong', function () {
    Livewire::test(ListProducts::class)->assertSeeText('Belum ada produk');

    Product::factory()->create(['is_active' => true]);

    Livewire::test(ListProducts::class)
        ->filterTable('is_active', false)
        ->assertSeeText('Tidak ada produk yang cocok');
});

it('bintang favorit menandai dan melepas favorit', function () {
    $product = Product::factory()->create();

    Livewire::test(ListProducts::class)->callAction(TestAction::make('toggleFavorite')->table($product));
    expect($product->refresh()->is_favorite)->toBeTrue();

    Livewire::test(ListProducts::class)->callAction(TestAction::make('toggleFavorite')->table($product));
    expect($product->refresh()->is_favorite)->toBeFalse();
});

it('tab cepat: favorit, aktif, habis, stok menipis', function () {
    $favorite = Product::factory()->create(['is_favorite' => true]);
    $inactive = Product::factory()->inactive()->create();
    $markedSoldOut = Product::factory()->create(['is_available' => false]);
    $empty = Product::factory()->tracked(0)->create();
    $low = Product::factory()->tracked(3)->create(['min_stock' => 5]);
    $enough = Product::factory()->tracked(10)->create(['min_stock' => 5]);

    Livewire::test(ListProducts::class)
        ->set('activeTab', 'favorite')
        ->assertCanSeeTableRecords([$favorite])
        ->assertCountTableRecords(1)
        ->set('activeTab', 'active')
        ->assertCanNotSeeTableRecords([$inactive])
        ->set('activeTab', 'sold_out')
        ->assertCanSeeTableRecords([$markedSoldOut, $empty])
        ->assertCountTableRecords(2)
        ->set('activeTab', 'low_stock')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$enough, $empty]);
});

it('menyimpan stok minimum dan favorit dari form', function () {
    $product = Product::factory()->tracked(10)->create();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['min_stock' => 4, 'is_favorite' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->min_stock)->toBe(4)
        ->and($product->is_favorite)->toBeTrue()
        ->and($product->isLowStock())->toBeFalse();
});

it('mengatur urutan tampil di kasir dengan seret-lepas', function () {
    [$a, $b, $c] = Product::factory()->count(3)->sequence(['sort_order' => 1], ['sort_order' => 2], ['sort_order' => 3])->create()->all();

    Livewire::test(ListProducts::class)->call('reorderTable', [$c->id, $a->id, $b->id]);

    expect(Product::query()->orderBy('sort_order')->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('tab cepat tampil di header card, bukan di atas tabel', function () {
    Product::factory()->create();

    $html = $this->get('/dashboard/products')->assertOk()->getContent();

    expect($html)->toContain('gs-card-tabs')
        ->and($html)->toContain('Stok menipis')
        ->and($html)->not->toContain('fi-sc-tabs');
});

it('tab aktif diingat di session dan tidak ditulis ke URL', function () {
    $property = new ReflectionProperty(ListProducts::class, 'activeTab');
    expect($property->getDeclaringClass()->getName())->toBe(ListProducts::class)
        ->and($property->getAttributes(Livewire\Attributes\Url::class))->toBeEmpty();

    Livewire::test(ListProducts::class)->set('activeTab', 'sold_out');

    // Halaman dibuka ulang (refresh / kembali dari menu lain) → tab yang sama
    Livewire::test(ListProducts::class)->assertSet('activeTab', 'sold_out');
});

it('tab tidak dikenal dari request atau session diabaikan', function () {
    Livewire::test(ListProducts::class)
        ->set('activeTab', 'tidak-ada')
        ->assertSet('activeTab', 'all');

    session()->put('gs.list_active_tab.'.md5(ListProducts::class), ['bukan' => 'string']);

    Livewire::test(ListProducts::class)->assertSet('activeTab', 'all');
});
