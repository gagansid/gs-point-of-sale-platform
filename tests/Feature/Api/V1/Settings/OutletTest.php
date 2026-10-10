<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create(['code' => 'JKT01', 'name' => 'Kopi Senja', 'tax_rate' => '0.00', 'rounding' => 0]);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->token = userToken($this->owner);
});

function outletBody(array $override = []): array
{
    return [
        'name' => 'Kopi Senja Dago', 'address' => 'Jl. Dago 1', 'timezone' => 'Asia/Jakarta',
        'tax_rate' => '11', 'tax_inclusive' => false, 'service_charge_rate' => '5', 'rounding' => 100,
        'receipt_header' => 'Kopi Senja', 'receipt_footer' => 'Terima kasih',
        'discount_limits' => ['cashier' => 10, 'supervisor' => 25],
        ...$override,
    ];
}

it('owner melihat setelan outlet', function () {
    $this->withToken($this->token)->getJson('/api/v1/outlet', apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.code', 'JKT01')
        ->assertJsonPath('data.name', 'Kopi Senja');
});

it('owner mengubah setelan; kode outlet tidak berubah', function () {
    $this->withToken($this->token)->putJson('/api/v1/outlet', outletBody(['code' => 'XXX']), apiHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Setelan outlet berhasil disimpan')
        ->assertJsonPath('data.name', 'Kopi Senja Dago')
        ->assertJsonPath('data.tax_rate', '11.00')
        ->assertJsonPath('data.rounding', 100)
        ->assertJsonPath('data.discount_limits.supervisor', 25)
        ->assertJsonPath('data.code', 'JKT01');
});

it('field yang tidak dikirim tetap; request ganda menghasilkan data sama', function () {
    $this->withToken($this->token)->putJson('/api/v1/outlet', ['tax_rate' => '10'], apiHeaders())->assertOk();
    freshAuth();
    $this->withToken($this->token)->putJson('/api/v1/outlet', ['tax_rate' => '10'], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.tax_rate', '10.00')
        ->assertJsonPath('data.name', 'Kopi Senja');

    expect(TenantContext::run($this->outlet->tenant_id, fn () => Outlet::query()->count()))->toBe(1);
});

it('validasi setelan outlet', function (array $body, string $field) {
    $response = $this->withToken($this->token)->putJson('/api/v1/outlet', $body, apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    expect($response->json('error.details'))->toHaveKey($field);
})->with([
    'pajak > 100' => [['tax_rate' => '101'], 'tax_rate'],
    'pajak 3 desimal' => [['tax_rate' => '1.123'], 'tax_rate'],
    'pembulatan aneh' => [['rounding' => 250], 'rounding'],
    'zona tidak dikenal' => [['timezone' => 'Europe/London'], 'timezone'],
    'diskon tanpa supervisor' => [['discount_limits' => ['cashier' => 10]], 'discount_limits.supervisor'],
    'nama terlalu panjang' => [['name' => str_repeat('a', 101)], 'name'],
]);

it('selain owner tidak boleh melihat & mengubah setelan', function (string $role) {
    $user = User::factory()->{$role}()->create(['tenant_id' => $this->outlet->tenant_id]);
    $token = userToken($user);

    assertApiError($this->withToken($token)->getJson('/api/v1/outlet', apiHeaders()), 'FORBIDDEN', 403);
    freshAuth();
    assertApiError($this->withToken($token)->putJson('/api/v1/outlet', outletBody(), apiHeaders()), 'FORBIDDEN', 403);
})->with(['manager', 'supervisor', 'cashier']);

it('isolasi tenant: hanya outlet milik sendiri yang berubah', function () {
    $foreign = Outlet::factory()->create(['name' => 'Tenant Lain']);

    $this->withToken($this->token)->putJson('/api/v1/outlet', outletBody(), apiHeaders())->assertOk();

    expect(Outlet::allTenants()->find($foreign->id)?->name)->toBe('Tenant Lain');
});

it('tenant hanya-baca: GET boleh, PUT ditolak SUBSCRIPTION_EXPIRED', function () {
    Tenant::query()->whereKey($this->outlet->tenant_id)->update(['subscription_ends_at' => now()->subDay()]);

    $this->withToken($this->token)->getJson('/api/v1/outlet', apiHeaders())->assertOk();
    freshAuth();
    assertApiError($this->withToken($this->token)->putJson('/api/v1/outlet', outletBody(), apiHeaders()), 'SUBSCRIPTION_EXPIRED', 403);
});
