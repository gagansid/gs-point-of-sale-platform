<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->manager = User::factory()->manager()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->token = userToken($this->manager);
});

it('manager menambah kategori di urutan terakhir', function () {
    TenantContext::run($this->outlet->tenant_id, fn () => Category::factory()->create(['sort_order' => 4]));

    $this->withToken($this->token)->postJson('/api/v1/categories', ['name' => 'Non Kopi'], apiHeaders())
        ->assertCreated()
        ->assertJsonPath('message', 'Kategori berhasil ditambahkan')
        ->assertJsonPath('data.name', 'Non Kopi')
        ->assertJsonPath('data.sort_order', 5)
        ->assertJsonPath('meta.idempotent_replay', false);
});

it('validasi: nama wajib & maks. 100 karakter', function (array $body) {
    assertApiError($this->withToken($this->token)->postJson('/api/v1/categories', $body, apiHeaders()), 'VALIDATION_ERROR', 422);
})->with([[['name' => '']], [['name' => str_repeat('a', 101)]], [['name' => 'Ok', 'id' => 'bukan-uuid']]]);

it('supervisor & kasir tidak boleh mengelola kategori', function (string $role) {
    $user = User::factory()->{$role}()->create(['tenant_id' => $this->outlet->tenant_id]);

    assertApiError($this->withToken(userToken($user))->postJson('/api/v1/categories', ['name' => 'X'], apiHeaders()), 'FORBIDDEN', 403);
})->with(['supervisor', 'cashier']);

it('isolasi tenant: kategori tenant lain → 404', function () {
    $foreign = Category::factory()->create();

    assertApiError($this->withToken($this->token)->putJson("/api/v1/categories/{$foreign->id}", ['name' => 'Bajak'], apiHeaders()), 'NOT_FOUND', 404);
    freshAuth();
    assertApiError($this->withToken($this->token)->deleteJson("/api/v1/categories/{$foreign->id}", [], apiHeaders()), 'NOT_FOUND', 404);

    expect(Category::allTenants()->find($foreign->id)?->name)->toBe($foreign->name);
});

it('request ganda dengan id yang sama tetap satu kategori', function () {
    $body = ['id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a', 'name' => 'Teh'];

    $this->withToken($this->token)->postJson('/api/v1/categories', $body, apiHeaders())->assertCreated();
    freshAuth();
    $this->withToken($this->token)->postJson('/api/v1/categories', [...$body, 'name' => 'Teh 2'], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'Teh')
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(Category::allTenants()->count())->toBe(1);
});

it('id milik tenant lain tidak bisa dipakai ulang', function () {
    $foreign = Category::factory()->create();

    assertApiError(
        $this->withToken($this->token)->postJson('/api/v1/categories', ['id' => $foreign->id, 'name' => 'X'], apiHeaders()),
        'NOT_FOUND',
        404,
    );
});

it('mengubah kategori', function () {
    $category = TenantContext::run($this->outlet->tenant_id, fn () => Category::factory()->create(['name' => 'Lama']));

    $this->withToken($this->token)->putJson("/api/v1/categories/{$category->id}", ['name' => 'Baru', 'sort_order' => 2], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'Baru')
        ->assertJsonPath('data.sort_order', 2);
});

it('menghapus kategori tanpa menghapus produknya', function () {
    [$category, $product] = TenantContext::run($this->outlet->tenant_id, function () {
        $category = Category::factory()->create();

        return [$category, Product::factory()->forCategory($category)->create()];
    });

    $this->withToken($this->token)->deleteJson("/api/v1/categories/{$category->id}", [], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data', null);

    expect(Category::allTenants()->find($category->id))->toBeNull()
        ->and(Product::allTenants()->find($product->id)?->category_id)->toBeNull();
});
