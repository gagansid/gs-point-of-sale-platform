<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create(['code' => 'JKT01']);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id, 'email' => 'owner@kopi.test']);
});

function login(array $override = []): array
{
    return ['email' => 'owner@kopi.test', 'password' => 'password', 'device_uid' => 'pixel-7-abc', ...$override];
}

it('owner login dan mendapat token beserta user, tenant, outlet', function () {
    $response = $this->postJson('/api/v1/auth/login', login(['email' => 'OWNER@kopi.test']), apiHeaders());

    $response->assertOk()
        ->assertJsonPath('message', 'Login berhasil')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $this->owner->id)
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.tenant.id', $this->outlet->tenant_id)
        ->assertJsonPath('data.outlet.code', 'JKT01')
        ->assertJsonPath('data.outlet.tax_rate', '11.00')
        ->assertJsonMissingPath('data.user.pin')
        ->assertJsonMissingPath('data.user.password');

    expect($response->json('data.user.permissions'))->toContain('device.manage', 'order.void');

    freshAuth();
    $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me', apiHeaders())->assertOk();
});

it('manager boleh login dengan email', function () {
    User::factory()->manager()->create(['tenant_id' => $this->outlet->tenant_id, 'email' => 'manager@kopi.test']);

    $this->postJson('/api/v1/auth/login', login(['email' => 'manager@kopi.test']), apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.role', 'manager');
});

it('validasi gagal untuk input tidak lengkap atau rusak', function (array $override, string $field) {
    $response = $this->postJson('/api/v1/auth/login', login($override), apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'email kosong' => [['email' => ''], 'email'],
    'email rusak' => [['email' => 'bukan-email'], 'email'],
    'device_uid kosong' => [['device_uid' => ''], 'device_uid'],
    'device_uid berbahaya' => [['device_uid' => '<script>'], 'device_uid'],
]);

it('kredensial salah: pesan sama untuk email tak terdaftar dan kata sandi salah', function (array $override) {
    $response = $this->postJson('/api/v1/auth/login', login($override), apiHeaders());

    assertApiError($response, 'UNAUTHENTICATED', 401);
    $response->assertJsonPath('message', 'Email atau kata sandi salah');
})->with([
    'kata sandi salah' => [['password' => 'salah']],
    'email tidak terdaftar' => [['email' => 'siapa@kopi.test']],
]);

it('menolak karyawan nonaktif', function () {
    $this->owner->update(['is_active' => false]);

    assertApiError($this->postJson('/api/v1/auth/login', login(), apiHeaders()), 'UNAUTHENTICATED', 401);
});

it('kasir & supervisor ditolak (harus login PIN)', function (string $state) {
    User::factory()->{$state}()->create(['tenant_id' => $this->outlet->tenant_id, 'email' => 'staf@kopi.test']);

    $response = $this->postJson('/api/v1/auth/login', login(['email' => 'staf@kopi.test']), apiHeaders());

    assertApiError($response, 'FORBIDDEN', 403);
    $response->assertJsonPath('message', 'Gunakan login PIN di perangkat kasir');
})->with(['cashier', 'supervisor']);

it('tenant ditangguhkan atau langganan habis tidak bisa login', function () {
    Tenant::query()->whereKey($this->outlet->tenant_id)->update(['status' => 'suspended']);

    assertApiError($this->postJson('/api/v1/auth/login', login(), apiHeaders()), 'TENANT_SUSPENDED', 403);
});

it('login ulang di device yang sama mengganti token lama (satu token per device)', function () {
    $first = $this->postJson('/api/v1/auth/login', login(), apiHeaders())->json('data.token');
    $second = $this->postJson('/api/v1/auth/login', login(), apiHeaders())->json('data.token');
    $this->postJson('/api/v1/auth/login', login(['device_uid' => 'tablet-lain']), apiHeaders())->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $this->owner->id)->count())->toBe(2)
        ->and(PersonalAccessToken::findToken($first))->toBeNull()
        ->and(PersonalAccessToken::findToken($second))->not->toBeNull();
});

it('membatasi percobaan login 5 kali per menit (anti brute force)', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', login(['password' => 'salah']), apiHeaders())->assertStatus(401);
    }

    assertApiError($this->postJson('/api/v1/auth/login', login(), apiHeaders()), 'TOO_MANY_REQUESTS', 429);
});
