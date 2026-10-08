<?php

declare(strict_types=1);

use App\Actions\Tenant\CreateTenantWithOwner;
use App\Actions\Tenant\Data\CreateTenantData;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenantId]);
    $this->cashier = User::factory()->cashier()->forOutlet($this->outlet)->create();

    TenantContext::run($this->tenantId, function () {
        $this->category = Category::factory()->create(['name' => 'Kopi', 'sort_order' => 1]);
        $this->size = OptionGroup::factory()->create(['name' => 'Ukuran']);
        Option::factory()->create(['option_group_id' => $this->size->id, 'tenant_id' => $this->tenantId, 'name' => 'Large', 'price_delta' => '5000.00']);
        $this->product = Product::factory()->forCategory($this->category)->create(['name' => 'Es Kopi Susu', 'price' => '22000.00']);
        $this->product->optionGroups()->attach($this->size->id, ['sort_order' => 0]);
        Product::factory()->inactive()->create(['name' => 'Menu Lama']);
        PaymentMethod::factory()->create(['name' => 'Tunai']);
        PaymentMethod::factory()->create(['name' => 'Nonaktif', 'is_active' => false]);
    });
    TenantContext::forget();

    // Data tenant lain tidak boleh ikut
    Product::factory()->create(['name' => 'Produk Tenant Lain']);
});

it('mengembalikan seluruh katalog aktif dalam satu respons', function () {
    $response = $this->withToken(userToken($this->cashier))->getJson('/api/v1/catalog', apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.categories.0.name', 'Kopi')
        ->assertJsonPath('data.products.*.name', ['Es Kopi Susu'])
        ->assertJsonPath('data.products.0.price', '22000.00')
        ->assertJsonPath('data.products.0.option_group_ids', [$this->size->id])
        ->assertJsonPath('data.option_groups.0.options.0.price_delta', '5000.00')
        ->assertJsonPath('data.payment_methods.*.name', ['Tunai'])
        ->assertHeader('ETag');
});

it('harga modal disembunyikan dari kasir, terlihat oleh owner', function () {
    $this->withToken(userToken($this->cashier))->getJson('/api/v1/catalog', apiHeaders())
        ->assertJsonMissingPath('data.products.0.cost_price');

    freshAuth();
    $this->withToken(userToken($this->owner))->getJson('/api/v1/catalog', apiHeaders())
        ->assertJsonPath('data.products.0.cost_price', '9000.00');
});

it('ETag: 304 tanpa body bila katalog tidak berubah, 200 setelah berubah', function () {
    $token = userToken($this->cashier);
    $etag = $this->withToken($token)->getJson('/api/v1/catalog', apiHeaders())->headers->get('ETag');

    freshAuth();
    $this->withToken($token)->getJson('/api/v1/catalog', apiHeaders(['If-None-Match' => $etag]))
        ->assertStatus(304)
        ->assertNoContent(304);

    $this->product->update(['price' => '23000.00']);

    freshAuth();
    $this->withToken($token)->getJson('/api/v1/catalog', apiHeaders(['If-None-Match' => $etag]))
        ->assertOk()
        ->assertJsonPath('data.products.0.price', '23000.00');
});

it('tanpa token → 401', function () {
    assertApiError($this->getJson('/api/v1/catalog', apiHeaders()), 'UNAUTHENTICATED', 401);
});

it('tenant baru otomatis punya 5 metode bayar bawaan', function () {
    $tenant = app(CreateTenantWithOwner::class)->handle(CreateTenantData::fromArray([
        'name' => 'Toko Baru', 'slug' => 'toko-baru', 'business_type' => 'retail', 'status' => 'trial',
        'subscription_ends_at' => null, 'outlet_name' => 'Pusat', 'outlet_code' => 'TB01', 'outlet_timezone' => 'Asia/Jakarta',
        'owner_name' => 'Ani', 'owner_email' => 'ani@toko.test', 'owner_password' => 'rahasia123',
    ]));

    $methods = PaymentMethod::forTenant($tenant->id)->orderBy('sort_order')->get();

    expect($methods->pluck('category')->map->value->all())->toBe(['cash', 'qris', 'transfer', 'debit', 'credit'])
        ->and($methods->where('requires_reference', true)->pluck('name')->all())->toBe(['Debit', 'Kredit']);
});
