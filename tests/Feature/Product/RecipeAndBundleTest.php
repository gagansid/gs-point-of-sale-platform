<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Enums\ProductKind;
use App\Enums\StockMovementType;
use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Models\Option;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Bahan baku, resep, opsi berbahan, paket (ADR 0020, Q97–Q99).
 * Bahan: Kopi (gram, modal 200/gram), Susu (ml, modal 20/ml). Es Kopi Susu = 18 g kopi + 150 ml susu.
 */
beforeEach(function () {
    $this->pos = posSetup();
    $this->ownerToken = userToken($this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]), $this->pos->device);
    $this->coffeeBeans = productApi($this, ['name' => 'Kopi', 'kind' => 'ingredient', 'unit' => 'gram', 'price' => 0, 'cost_price' => 200, 'stock_qty' => 1000])->json('data.id');
    $this->milk = productApi($this, ['name' => 'Susu', 'kind' => 'ingredient', 'unit' => 'ml', 'price' => 0, 'cost_price' => 20, 'stock_qty' => 2000])->json('data.id');
});

function productApi(object $test, array $body, ?string $id = null): TestResponse
{
    freshAuth();

    return $id === null
        ? $test->withToken($test->ownerToken)->postJson('/api/v1/products', $body, apiHeaders())
        : $test->withToken($test->ownerToken)->putJson("/api/v1/products/{$id}", $body, apiHeaders());
}

function recipeStock(object $test, string $productId): int
{
    return stockOf($productId, $test->pos->outlet->id)->stock_qty;
}

function recipeSale(object $test, array $items): Order
{
    return TenantContext::run($test->pos->tenantId, fn (): Order => app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 500000]],
    ]))['order']);
}

it('bahan baku: stok selalu dilacak, tidak tampil di katalog, tidak bisa dijual', function () {
    $ingredient = Product::allTenants()->findOrFail($this->coffeeBeans);
    expect($ingredient->kind)->toBe(ProductKind::Ingredient)->and($ingredient->track_stock)->toBeTrue()->and(recipeStock($this, $this->coffeeBeans))->toBe(1000);

    freshAuth();
    $catalog = $this->withToken($this->pos->token)->getJson('/api/v1/catalog', apiHeaders())->assertOk()->json('data.products');
    expect(collect($catalog)->pluck('id'))->not->toContain($this->coffeeBeans);

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->coffeeBeans, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 1000]],
    ], apiHeaders()), 'VALIDATION_ERROR', 422);
});

it('produk beresep: stok bahan berkurang, HPP dari modal bahan, void membalik persis walau resep diubah', function () {
    $latte = productApi($this, ['name' => 'Kopi Susu', 'price' => 25000, 'track_stock' => true, 'components' => [
        ['component_id' => $this->coffeeBeans, 'qty' => 18], ['component_id' => $this->milk, 'qty' => 150],
    ]])->assertCreated()->assertJsonPath('data.kind', 'regular')->assertJsonPath('data.track_stock', false)
        ->assertJsonCount(2, 'data.components')->json('data.id');

    $order = recipeSale($this, [['product_id' => $latte, 'qty' => 2]]);

    expect(recipeStock($this, $this->coffeeBeans))->toBe(964)->and(recipeStock($this, $this->milk))->toBe(1700)
        // 18 × 200 + 150 × 20 = 6.600 per porsi
        ->and(TenantContext::run($this->pos->tenantId, fn () => $order->items()->value('unit_cost')))->toBe('6600.00');

    // Resep diubah setelah transaksi; void mengembalikan stok sesuai penjualan
    productApi($this, ['name' => 'Kopi Susu', 'price' => 25000, 'components' => [['component_id' => $this->coffeeBeans, 'qty' => 30]]], $latte)->assertOk();
    TenantContext::run($this->pos->tenantId, fn () => app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Salah', null));

    expect(recipeStock($this, $this->coffeeBeans))->toBe(1000)->and(recipeStock($this, $this->milk))->toBe(2000)
        ->and(StockMovement::allTenants()->where('type', StockMovementType::VoidReturn)->count())->toBe(2);
});

it('opsi berbahan: Extra shot memotong kopi per item', function () {
    $extra = TenantContext::run($this->pos->tenantId, fn () => Option::factory()->create([
        'option_group_id' => $this->pos->size->id, 'tenant_id' => $this->pos->tenantId, 'name' => 'Double', 'price_delta' => '5000.00',
    ]));
    freshAuth();
    $this->withToken($this->ownerToken)->putJson("/api/v1/option-groups/{$this->pos->size->id}", [
        'name' => 'Ukuran', 'min_select' => 1, 'max_select' => 1, 'options' => [
            ['id' => $this->pos->regular->id, 'name' => 'Regular', 'price_delta' => 0],
            ['id' => $extra->id, 'name' => 'Double', 'price_delta' => 5000, 'components' => [['component_id' => $this->coffeeBeans, 'qty' => 18]]],
        ],
    ], apiHeaders())->assertOk();

    recipeSale($this, [['product_id' => $this->pos->coffee->id, 'qty' => 3, 'option_ids' => [$extra->id]]]);

    expect(recipeStock($this, $this->coffeeBeans))->toBe(946);
});

it('paket: wajib berisi, stok dipotong dari isi (termasuk resep isi), tanpa stok sendiri', function () {
    assertApiError(productApi($this, ['name' => 'Paket Kosong', 'kind' => 'bundle', 'price' => 30000]), 'VALIDATION_ERROR', 422);

    $latte = productApi($this, ['name' => 'Kopi Susu', 'price' => 25000, 'components' => [['component_id' => $this->coffeeBeans, 'qty' => 18]]])->json('data.id');
    $bundle = productApi($this, ['name' => 'Paket Pagi', 'kind' => 'bundle', 'price' => 40000, 'track_stock' => true, 'components' => [
        ['component_id' => $latte, 'qty' => 1], ['component_id' => $this->pos->croissant->id, 'qty' => 1],
    ]])->assertCreated()->assertJsonPath('data.track_stock', false)->json('data.id');

    recipeSale($this, [['product_id' => $bundle, 'qty' => 2]]);

    expect(recipeStock($this, $this->coffeeBeans))->toBe(964)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(8)
        ->and(StockMovement::allTenants()->where('product_id', $bundle)->exists())->toBeFalse();
});

it('validasi resep: diri sendiri, paket sebagai komponen, siklus, komponen tenant lain', function () {
    $a = productApi($this, ['name' => 'A', 'price' => 1000, 'components' => [['component_id' => $this->coffeeBeans, 'qty' => 1]]])->json('data.id');
    $b = productApi($this, ['name' => 'B', 'price' => 1000, 'components' => [['component_id' => $a, 'qty' => 1]]])->json('data.id');
    $bundle = productApi($this, ['name' => 'Paket', 'kind' => 'bundle', 'price' => 1000, 'components' => [['component_id' => $a, 'qty' => 1]]])->json('data.id');

    assertApiError(productApi($this, ['name' => 'A', 'price' => 1000, 'components' => [['component_id' => $a, 'qty' => 1]]], $a), 'VALIDATION_ERROR', 422);
    assertApiError(productApi($this, ['name' => 'C', 'price' => 1000, 'components' => [['component_id' => $bundle, 'qty' => 1]]]), 'VALIDATION_ERROR', 422);
    assertApiError(productApi($this, ['name' => 'A', 'price' => 1000, 'components' => [['component_id' => $b, 'qty' => 1]]], $a), 'VALIDATION_ERROR', 422);
    // A dipakai di resep B & paket: tidak bisa menjadi paket
    assertApiError(productApi($this, ['name' => 'A', 'kind' => 'bundle', 'price' => 1000, 'components' => [['component_id' => $this->milk, 'qty' => 1]]], $a), 'VALIDATION_ERROR', 422);

    $foreign = posSetup();
    assertApiError(productApi($this, ['name' => 'D', 'price' => 1000, 'components' => [['component_id' => $foreign->croissant->id, 'qty' => 1]]]), 'VALIDATION_ERROR', 422);
});

it('ubah produk tanpa field jenis/resep: jenis & resep lama tetap', function () {
    $latte = productApi($this, ['name' => 'Kopi Susu', 'price' => 25000, 'components' => [['component_id' => $this->coffeeBeans, 'qty' => 18]]])->json('data.id');

    productApi($this, ['name' => 'Kopi Susu Gula Aren', 'price' => 27000], $latte)->assertOk()
        ->assertJsonPath('data.kind', 'regular')->assertJsonCount(1, 'data.components');
    productApi($this, ['name' => 'Kopi', 'price' => 0], $this->coffeeBeans)->assertOk()->assertJsonPath('data.kind', 'ingredient');
});

it('dashboard: buat paket dari form produk', function () {
    freshAuth();
    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->pos->tenantId);
    $this->actingAs($this->owner, 'web');
    CurrentOutlet::set($this->owner);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Paket Hemat', 'kind' => ProductKind::Bundle->value, 'unit' => 'pcs', 'price' => 30000,
            'components' => [['component_id' => $this->pos->croissant->id, 'qty' => 2]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $bundle = Product::query()->where('name', 'Paket Hemat')->sole();
    expect($bundle->kind)->toBe(ProductKind::Bundle)->and($bundle->components()->value('qty'))->toBe(2);
});

it('retur produk beresep mengembalikan bahan sesuai resep × qty retur', function () {
    $latte = productApi($this, ['name' => 'Kopi Susu', 'price' => 25000, 'components' => [['component_id' => $this->coffeeBeans, 'qty' => 18]]])->json('data.id');
    $order = recipeSale($this, [['product_id' => $latte, 'qty' => 2]]);
    $itemId = TenantContext::run($this->pos->tenantId, fn () => $order->items()->value('id'));

    freshAuth();
    $this->withToken(userToken($this->pos->supervisor, $this->pos->device))->postJson("/api/v1/orders/{$order->id}/refunds", [
        'id' => uuid(), 'reason' => 'Tumpah', 'method' => 'manual', 'items' => [['order_item_id' => $itemId, 'qty' => 1]],
    ], apiHeaders())->assertCreated();

    expect(recipeStock($this, $this->coffeeBeans))->toBe(982);
});
