<?php

declare(strict_types=1);

use App\Actions\Payment\CreateDefaultPaymentMethods;
use App\Enums\PaymentCategory;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->tenantId = $this->outlet->tenant_id;
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenantId]);
    $this->token = userToken($this->owner);
    $this->methods = TenantContext::run($this->tenantId, function () {
        app(CreateDefaultPaymentMethods::class)->handle();

        return PaymentMethod::query()->get()->keyBy(fn (PaymentMethod $m) => $m->category->value);
    });
});

it('owner melihat semua metode termasuk yang nonaktif, berurutan', function () {
    $this->methods['credit']->update(['is_active' => false]);

    $this->withToken($this->token)->getJson('/api/v1/payment-methods', apiHeaders())
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.category', 'cash')
        ->assertJsonPath('data.4.is_active', false);
});

it('mengubah nama, wajib referensi, aktif, dan urutan; field lain tetap', function () {
    $qris = $this->methods['qris'];

    $this->withToken($this->token)->putJson("/api/v1/payment-methods/{$qris->id}", [
        'name' => 'QRIS BCA', 'requires_reference' => true, 'sort_order' => 9,
    ], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'QRIS BCA')
        ->assertJsonPath('data.requires_reference', true)
        ->assertJsonPath('data.sort_order', 9)
        ->assertJsonPath('data.is_active', true);

    freshAuth();
    $this->withToken($this->token)->putJson("/api/v1/payment-methods/{$qris->id}", ['is_active' => false], apiHeaders())
        ->assertOk()->assertJsonPath('data.name', 'QRIS BCA');
});

it('tunai tidak bisa dinonaktifkan', function () {
    $response = $this->withToken($this->token)->putJson("/api/v1/payment-methods/{$this->methods['cash']->id}", ['is_active' => false], apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    expect($response->json('error.details'))->toHaveKey('is_active')
        ->and($this->methods['cash']->refresh()->is_active)->toBeTrue();
});

it('validasi', function (array $body, string $field) {
    $response = $this->withToken($this->token)->putJson("/api/v1/payment-methods/{$this->methods['qris']->id}", $body, apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    expect($response->json('error.details'))->toHaveKey($field);
})->with([
    'nama terlalu panjang' => [['name' => str_repeat('a', 51)], 'name'],
    'urutan negatif' => [['sort_order' => -1], 'sort_order'],
    'aktif bukan boolean' => [['is_active' => 'ya'], 'is_active'],
]);

it('selain owner tidak boleh mengelola metode pembayaran', function (string $role) {
    $user = TenantContext::run($this->tenantId, fn () => User::factory()->{$role}()->forOutlet($this->outlet)->create());
    $token = userToken($user);

    assertApiError($this->withToken($token)->getJson('/api/v1/payment-methods', apiHeaders()), 'FORBIDDEN', 403);
    freshAuth();
    assertApiError($this->withToken($token)->putJson("/api/v1/payment-methods/{$this->methods['qris']->id}", ['name' => 'X'], apiHeaders()), 'FORBIDDEN', 403);
})->with(['manager', 'supervisor', 'cashier']);

it('isolasi tenant: metode tenant lain → 404', function () {
    $foreign = PaymentMethod::factory()->create(['category' => PaymentCategory::Qris]);

    assertApiError($this->withToken($this->token)->putJson("/api/v1/payment-methods/{$foreign->id}", ['name' => 'X'], apiHeaders()), 'NOT_FOUND', 404);
});

it('metode nonaktif ditolak saat checkout (sudah lewat AllocatePayments) & PUT ganda tetap sama', function () {
    $body = ['name' => 'Debit BRI'];
    $id = $this->methods['debit']->id;

    $this->withToken($this->token)->putJson("/api/v1/payment-methods/{$id}", $body, apiHeaders())->assertOk();
    freshAuth();
    $this->withToken($this->token)->putJson("/api/v1/payment-methods/{$id}", $body, apiHeaders())->assertOk();

    expect(TenantContext::run($this->tenantId, fn () => PaymentMethod::query()->where('name', 'Debit BRI')->count()))->toBe(1);
});

it('tenant hanya-baca: GET boleh, PUT ditolak', function () {
    Tenant::query()->whereKey($this->tenantId)->update(['subscription_ends_at' => now()->subDay()]);

    $this->withToken($this->token)->getJson('/api/v1/payment-methods', apiHeaders())->assertOk();
    freshAuth();
    assertApiError($this->withToken($this->token)->putJson("/api/v1/payment-methods/{$this->methods['qris']->id}", ['name' => 'X'], apiHeaders()), 'SUBSCRIPTION_EXPIRED', 403);
});
