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

    Livewire::test(ListProducts::class)->callAction(TestAction::make('toggleAvailability')->table($product));

    expect($product->refresh()->is_available)->toBeFalse();
});

it('form edit tidak bisa membuka produk tenant lain', function () {
    $foreign = TenantContext::run(
        Tenant::factory()->create()->id,
        fn () => Product::factory()->create(),
    );

    $this->get("/dashboard/products/{$foreign->id}/edit")->assertNotFound();
});
