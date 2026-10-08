<?php

declare(strict_types=1);

use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    $this->token = userToken(User::factory()->owner()->create(['tenant_id' => $this->tenantId]));
});

function groupBody(array $override = []): array
{
    return [
        'name' => 'Ukuran',
        'min_select' => 1,
        'max_select' => 1,
        'options' => [['name' => 'Regular', 'price_delta' => 0], ['name' => 'Large', 'price_delta' => '5000']],
        ...$override,
    ];
}

it('menambah grup opsi beserta opsinya', function () {
    $this->withToken($this->token)->postJson('/api/v1/option-groups', groupBody(), apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ukuran')
        ->assertJsonPath('data.options.*.name', ['Regular', 'Large'])
        ->assertJsonPath('data.options.1.price_delta', '5000.00');

    expect(Option::allTenants()->pluck('tenant_id')->unique()->all())->toBe([$this->tenantId]);
});

it('validasi gagal', function (array $override, string $field) {
    $response = $this->withToken($this->token)->postJson('/api/v1/option-groups', groupBody($override), apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'maks < min' => [['min_select' => 2, 'max_select' => 1], 'max_select'],
    'tanpa opsi' => [['options' => []], 'options'],
    'nama opsi dobel' => [['options' => [['name' => 'A'], ['name' => 'A']]], 'options.0.name'],
    'harga minus' => [['options' => [['name' => 'A', 'price_delta' => -100]]], 'options.0.price_delta'],
]);

it('supervisor tidak boleh mengelola grup opsi', function () {
    $supervisor = User::factory()->supervisor()->forOutlet($this->outlet)->create();

    assertApiError($this->withToken(userToken($supervisor))->postJson('/api/v1/option-groups', groupBody(), apiHeaders()), 'FORBIDDEN', 403);
});

it('mengubah: opsi ber-id diubah, baru ditambah, yang tidak dikirim dihapus', function () {
    [$group, $regular, $large] = TenantContext::run($this->tenantId, function () {
        $group = OptionGroup::factory()->create();

        return [
            $group,
            Option::factory()->create(['option_group_id' => $group->id, 'tenant_id' => $this->tenantId, 'name' => 'Regular']),
            Option::factory()->create(['option_group_id' => $group->id, 'tenant_id' => $this->tenantId, 'name' => 'Large']),
        ];
    });

    $this->withToken($this->token)->putJson("/api/v1/option-groups/{$group->id}", groupBody([
        'options' => [
            ['id' => $large->id, 'name' => 'Large', 'price_delta' => 6000],
            ['name' => 'Extra Large', 'price_delta' => 9000],
        ],
    ]), apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.options.*.name', ['Large', 'Extra Large'])
        ->assertJsonPath('data.options.0.id', $large->id)
        ->assertJsonPath('data.options.0.price_delta', '6000.00');

    expect(Option::allTenants()->find($regular->id))->toBeNull();
});

it('isolasi: id opsi milik grup/tenant lain tidak bisa diambil alih', function () {
    $foreignOption = Option::factory()->create(['name' => 'Milik Orang']);
    $group = TenantContext::run($this->tenantId, fn () => OptionGroup::factory()->create());

    $this->withToken($this->token)->putJson("/api/v1/option-groups/{$group->id}", groupBody([
        'options' => [['id' => $foreignOption->id, 'name' => 'Diubah', 'price_delta' => 1]],
    ]), apiHeaders())->assertOk();

    expect(Option::allTenants()->find($foreignOption->id)?->name)->toBe('Milik Orang');
});

it('isolasi: grup tenant lain → 404', function () {
    $foreign = OptionGroup::factory()->create();

    assertApiError($this->withToken($this->token)->putJson("/api/v1/option-groups/{$foreign->id}", groupBody(), apiHeaders()), 'NOT_FOUND', 404);
});

it('request ganda dengan id yang sama tetap satu grup', function () {
    $body = groupBody(['id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b80']);

    $this->withToken($this->token)->postJson('/api/v1/option-groups', $body, apiHeaders())->assertCreated();
    freshAuth();
    $this->withToken($this->token)->postJson('/api/v1/option-groups', $body, apiHeaders())->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    expect(OptionGroup::allTenants()->count())->toBe(1)->and(Option::allTenants()->count())->toBe(2);
});

it('menghapus grup opsi melepasnya dari produk', function () {
    [$group, $product] = TenantContext::run($this->tenantId, function () {
        $group = OptionGroup::factory()->create();
        $product = Product::factory()->create();
        $product->optionGroups()->attach($group->id);

        return [$group, $product];
    });

    $this->withToken($this->token)->deleteJson("/api/v1/option-groups/{$group->id}", [], apiHeaders())->assertOk();

    expect(OptionGroup::allTenants()->find($group->id))->toBeNull()
        ->and($product->optionGroups()->count())->toBe(0);
});
