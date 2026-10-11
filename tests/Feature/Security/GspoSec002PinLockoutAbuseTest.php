<?php

declare(strict_types=1);

use App\Actions\User\UnlockEmployeePin;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * GSPOS-SEC-002 (docs/security/reports/2026-10-10-audit.md): percobaan PIN salah dari satu
 * sumber (perangkat / jalur approval) tidak boleh mengunci login PIN korban di perangkat lain.
 */
beforeEach(function () {
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->attackerDevice = Device::factory()->forOutlet($this->pos->outlet)->create(['device_uid' => 'kasir-penyerang']);
    });
});

/** PIN login lewat device token tertentu dari IP tertentu (hindari throttle:auth per IP di test). */
function sec002PinLogin(object $test, Device $device, string $userId, string $pin, string $ip): TestResponse
{
    freshAuth();

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken(deviceToken($device))
        ->postJson('/api/v1/auth/pin-login', ['user_id' => $userId, 'pin' => $pin], apiHeaders());
}

it('5 PIN salah dari perangkat penyerang tidak mengunci login kasir di perangkatnya sendiri', function () {
    foreach (range(1, 5) as $_) {
        sec002PinLogin($this, $this->attackerDevice, $this->pos->cashier->id, '000000', '10.0.0.66');
    }

    // Korban login dengan PIN benar di perangkatnya, dari IP lain
    sec002PinLogin($this, $this->pos->device, $this->pos->cashier->id, '123456', '10.0.0.10')->assertOk();
});

it('5 PIN approver salah lewat void tidak mengunci login PIN supervisor', function () {
    freshAuth();
    $orderId = $this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ], apiHeaders())->assertCreated()->json('data.id');

    // Kasir menebak PIN supervisor lewat jalur approval (hanya throttle:api, bukan throttle:auth)
    foreach (range(1, 5) as $_) {
        freshAuth();
        $this->withToken($this->pos->token)->postJson("/api/v1/orders/{$orderId}/void", [
            'reason' => 'x', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '000000',
        ], apiHeaders());
    }

    sec002PinLogin($this, $this->pos->device, $this->pos->supervisor->id, '123456', '10.0.0.11')->assertOk();
});

it('kunci per perangkat: PIN benar di perangkat penyerang tetap ditolak 423 selama terkunci', function () {
    foreach (range(1, 5) as $i) {
        sec002PinLogin($this, $this->attackerDevice, $this->pos->cashier->id, '000000', "10.1.0.{$i}");
    }

    assertApiError(sec002PinLogin($this, $this->attackerDevice, $this->pos->cashier->id, '123456', '10.1.0.9'), 'PIN_LOCKED', 423);
});

it('15 PIN salah dari banyak perangkat → dikunci di semua perangkat (brute force terdistribusi)', function () {
    $devices = TenantContext::run($this->pos->tenantId, fn () => Device::factory()->forOutlet($this->pos->outlet)->count(3)->create());

    foreach ($devices as $d => $device) {
        foreach (range(1, 5) as $i) {
            sec002PinLogin($this, $device, $this->pos->cashier->id, '000000', "10.2.{$d}.{$i}");
        }
    }

    expect($this->pos->cashier->refresh()->isPinLocked())->toBeTrue();
    assertApiError(sec002PinLogin($this, $this->pos->device, $this->pos->cashier->id, '123456', '10.2.9.9'), 'PIN_LOCKED', 423);
});

it('satu perangkat menebak PIN banyak karyawan → 429 setelah 20 salah', function () {
    $staff = TenantContext::run($this->pos->tenantId, fn () => User::factory()->cashier()->forOutlet($this->pos->outlet)->count(5)->create());

    foreach ($staff as $u => $user) {
        foreach (range(1, 4) as $i) {
            sec002PinLogin($this, $this->attackerDevice, $user->id, '000000', "10.3.{$u}.{$i}");
        }
    }

    assertApiError(sec002PinLogin($this, $this->attackerDevice, $this->pos->cashier->id, '000000', '10.3.9.9'), 'TOO_MANY_REQUESTS', 429);
});

it('unlock owner membuka kunci per perangkat juga', function () {
    foreach (range(1, 5) as $i) {
        sec002PinLogin($this, $this->attackerDevice, $this->pos->cashier->id, '000000', "10.4.0.{$i}");
    }

    TenantContext::run($this->pos->tenantId, fn () => app(UnlockEmployeePin::class)->handle($this->pos->cashier));

    sec002PinLogin($this, $this->attackerDevice, $this->pos->cashier->id, '123456', '10.4.0.9')->assertOk();
});
