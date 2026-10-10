<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->token = userToken($this->owner);
});

it('menampilkan user, tenant, dan setelan outlet', function () {
    $this->withToken($this->token)->getJson('/api/v1/auth/me', apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.id', $this->owner->id)
        ->assertJsonPath('data.tenant.status', 'active')
        ->assertJsonPath('data.outlet.timezone', 'Asia/Jakarta')
        ->assertJsonPath('data.outlet.rounding', 100)
        ->assertJsonPath('data.user.last_login_at', fn (?string $v) => $v === null || str_ends_with($v, 'Z'));
});

it('tanpa token → 401', function () {
    assertApiError($this->getJson('/api/v1/auth/me', apiHeaders()), 'UNAUTHENTICATED', 401);
});

it('device token tidak bisa membaca /auth/me', function () {
    $device = Device::factory()->forOutlet($this->outlet)->create();

    assertApiError($this->withToken(deviceToken($device))->getJson('/api/v1/auth/me', apiHeaders()), 'UNAUTHENTICATED', 401);
});

it('karyawan yang dinonaktifkan langsung kehilangan akses', function () {
    $this->owner->update(['is_active' => false]);

    assertApiError($this->withToken($this->token)->getJson('/api/v1/auth/me', apiHeaders()), 'UNAUTHENTICATED', 401);
});

it('tenant ditangguhkan → 403 TENANT_SUSPENDED', function () {
    Tenant::query()->whereKey($this->outlet->tenant_id)->update(['status' => TenantStatus::Suspended]);

    assertApiError($this->withToken($this->token)->getJson('/api/v1/auth/me', apiHeaders()), 'TENANT_SUSPENDED', 403);
});

it('langganan habis → hanya-baca: GET boleh, perubahan 403 SUBSCRIPTION_EXPIRED, logout boleh (Q32)', function () {
    Tenant::query()->whereKey($this->outlet->tenant_id)->update(['subscription_ends_at' => now()->subDay()]);

    $this->withToken($this->token)->getJson('/api/v1/auth/me', apiHeaders())->assertOk();

    freshAuth();
    assertApiError(
        $this->withToken($this->token)->postJson('/api/v1/categories', ['name' => 'Baru'], apiHeaders()),
        'SUBSCRIPTION_EXPIRED',
        403,
    );

    freshAuth();
    $this->withToken($this->token)->postJson('/api/v1/auth/logout', [], apiHeaders())->assertOk();
});

it('logout menghapus token yang sedang dipakai saja', function () {
    // Token kedua di device lain (nama berbeda) tidak boleh ikut terhapus
    $other = $this->owner->createToken('app:tablet-lain')->plainTextToken;

    $this->withToken($this->token)->postJson('/api/v1/auth/logout', [], apiHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Logout berhasil')
        ->assertJsonPath('data', null);

    freshAuth();
    assertApiError($this->withToken($this->token)->getJson('/api/v1/auth/me', apiHeaders()), 'UNAUTHENTICATED', 401);

    freshAuth();
    $this->withToken($other)->getJson('/api/v1/auth/me', apiHeaders())->assertOk();
});
