<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->device = Device::factory()->forOutlet($this->outlet)->create(['device_uid' => 'kasir-1']);
    $this->token = deviceToken($this->device);
});

it('menampilkan karyawan aktif ber-PIN di outlet device, tanpa data rahasia', function () {
    $tenantId = $this->outlet->tenant_id;
    $budi = User::factory()->cashier()->forOutlet($this->outlet)->create(['name' => 'Budi']);
    $sari = User::factory()->supervisor()->forOutlet($this->outlet)->create(['name' => 'Sari']);
    $owner = User::factory()->owner()->create(['tenant_id' => $tenantId, 'name' => 'Owner']);
    User::factory()->cashier()->forOutlet($this->outlet)->inactive()->create(['name' => 'Mantan']);
    User::factory()->cashier()->forOutlet($this->outlet)->create(['name' => 'Tanpa PIN', 'pin' => null]);
    $otherOutlet = TenantContext::run($tenantId, fn () => Outlet::factory()->create());
    User::factory()->cashier()->forOutlet($otherOutlet)->create(['name' => 'Outlet Lain']);
    User::factory()->cashier()->create(['name' => 'Tenant Lain']);

    $response = $this->withToken($this->token)->getJson('/api/v1/devices/kasir-1/cashiers', apiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.*.name', ['Budi', 'Owner', 'Sari'])
        ->assertJsonPath('data.0.id', $budi->id)
        ->assertJsonPath('data.0.role_label', 'Kasir')
        ->assertJsonMissingPath('data.0.email')
        ->assertJsonMissingPath('data.0.pin');

    expect([$sari->id, $owner->id])->each->toBeString();
});

it('menolak token user (harus device token)', function () {
    $owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);

    assertApiError(
        $this->withToken(userToken($owner))->getJson('/api/v1/devices/kasir-1/cashiers', apiHeaders()),
        'DEVICE_NOT_REGISTERED',
        403,
    );
});

it('device token hanya berlaku untuk device-nya sendiri', function () {
    $other = Device::factory()->forOutlet($this->outlet)->create(['device_uid' => 'kasir-2']);

    assertApiError(
        $this->withToken($this->token)->getJson("/api/v1/devices/{$other->device_uid}/cashiers", apiHeaders()),
        'DEVICE_NOT_REGISTERED',
        403,
    );
});

it('device yang dicabut ditolak', function () {
    $this->device->forceFill(['revoked_at' => now()])->save();

    assertApiError(
        $this->withToken($this->token)->getJson('/api/v1/devices/kasir-1/cashiers', apiHeaders()),
        'DEVICE_NOT_REGISTERED',
        403,
    );
});

it('tenant ditangguhkan ditolak', function () {
    Tenant::query()->whereKey($this->outlet->tenant_id)->update(['status' => 'suspended']);

    assertApiError(
        $this->withToken($this->token)->getJson('/api/v1/devices/kasir-1/cashiers', apiHeaders()),
        'TENANT_SUSPENDED',
        403,
    );
});

it('mencatat waktu terakhir device terlihat', function () {
    $this->device->forceFill(['last_seen_at' => now()->subHour()])->save();

    $this->withToken($this->token)->getJson('/api/v1/devices/kasir-1/cashiers', apiHeaders(['X-App-Version' => '1.0.5']));

    $device = Device::allTenants()->find($this->device->id);
    expect($device?->last_seen_at?->isAfter(now()->subMinute()))->toBeTrue()
        ->and($device?->app_version)->toBe('1.0.5');
});
