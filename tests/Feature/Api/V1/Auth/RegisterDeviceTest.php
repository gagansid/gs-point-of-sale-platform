<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);
});

function deviceBody(array $override = []): array
{
    return ['name' => 'Kasir Depan', 'device_uid' => 'sunmi-v2-001', 'platform' => 'android', 'app_version' => '1.0.0', ...$override];
}

it('owner mendaftarkan device dan mendapat device token sekali', function () {
    $response = $this->withToken(userToken($this->owner))
        ->postJson('/api/v1/devices', deviceBody(), apiHeaders());

    $response->assertCreated()
        ->assertJsonPath('message', 'Perangkat berhasil didaftarkan')
        ->assertJsonPath('data.device.device_uid', 'sunmi-v2-001')
        ->assertJsonPath('data.device.outlet_id', $this->outlet->id);

    $device = Device::allTenants()->sole();
    expect($device->tenant_id)->toBe($this->outlet->tenant_id);

    // Device token langsung bisa dipakai di layar PIN
    freshAuth();
    $this->withToken($response->json('data.device_token'))
        ->getJson('/api/v1/devices/sunmi-v2-001/cashiers', apiHeaders())
        ->assertOk();
});

it('validasi gagal untuk data device tidak valid', function (array $override, string $field) {
    $response = $this->withToken(userToken($this->owner))->postJson('/api/v1/devices', deviceBody($override), apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'nama kosong' => [['name' => ''], 'name'],
    'platform tidak dikenal' => [['platform' => 'windows'], 'platform'],
    'versi rusak' => [['app_version' => 'v1'], 'app_version'],
]);

it('manager tidak punya permission device.manage', function () {
    $manager = User::factory()->manager()->create(['tenant_id' => $this->outlet->tenant_id]);

    assertApiError(
        $this->withToken(userToken($manager))->postJson('/api/v1/devices', deviceBody(), apiHeaders()),
        'FORBIDDEN',
        403,
    );
});

it('device token tidak bisa dipakai untuk endpoint user', function () {
    $device = Device::factory()->forOutlet($this->outlet)->create();

    assertApiError(
        $this->withToken(deviceToken($device))->postJson('/api/v1/devices', deviceBody(), apiHeaders()),
        'UNAUTHENTICATED',
        401,
    );
});

it('isolasi tenant: tidak bisa mendaftarkan device ke outlet tenant lain', function () {
    $foreign = Outlet::factory()->create();

    $response = $this->withToken(userToken($this->owner))
        ->postJson('/api/v1/devices', deviceBody(['outlet_id' => $foreign->id]), apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    expect(Device::allTenants()->count())->toBe(0);
});

it('device_uid yang sama boleh dipakai di tenant lain', function () {
    Device::factory()->create(['device_uid' => 'sunmi-v2-001']);

    $this->withToken(userToken($this->owner))->postJson('/api/v1/devices', deviceBody(), apiHeaders())->assertCreated();

    expect(Device::allTenants()->where('device_uid', 'sunmi-v2-001')->count())->toBe(2);
});

it('daftar ulang device yang sama: tetap satu device, token lama dicabut, device dicabut aktif lagi', function () {
    $token = userToken($this->owner);
    $first = $this->withToken($token)->postJson('/api/v1/devices', deviceBody(), apiHeaders())->json('data.device_token');
    Device::allTenants()->update(['revoked_at' => now()]);

    freshAuth();
    $second = $this->withToken($token)->postJson('/api/v1/devices', deviceBody(['name' => 'Kasir Baru']), apiHeaders());
    $second->assertCreated()->assertJsonPath('data.device.name', 'Kasir Baru');

    expect(Device::allTenants()->count())->toBe(1)
        ->and(Device::allTenants()->sole()->isRevoked())->toBeFalse();

    freshAuth();
    assertApiError(
        $this->withToken($first)->getJson('/api/v1/devices/sunmi-v2-001/cashiers', apiHeaders()),
        'UNAUTHENTICATED',
        401,
    );
});
