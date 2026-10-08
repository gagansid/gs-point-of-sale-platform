<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Outlet;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->device = Device::factory()->forOutlet($this->outlet)->create(['device_uid' => 'kasir-1']);
    $this->deviceToken = deviceToken($this->device);
    $this->cashier = User::factory()->cashier()->forOutlet($this->outlet)->create(['name' => 'Budi']);
});

function pinLogin(object $test, string $userId, string $pin): TestResponse
{
    freshAuth();

    return $test->withToken($test->deviceToken)
        ->postJson('/api/v1/auth/pin-login', ['user_id' => $userId, 'pin' => $pin], apiHeaders());
}

it('kasir login dengan PIN dan mendapat token 1 shift (maks. 16 jam) yang terikat ke device', function () {
    $response = pinLogin($this, $this->cashier->id, '123456');

    $response->assertOk()
        ->assertJsonPath('data.user.id', $this->cashier->id)
        ->assertJsonPath('data.user.role', 'cashier')
        ->assertJsonPath('data.outlet.id', $this->outlet->id);

    expect($response->json('data.user.permissions'))->not->toContain('order.void');

    $token = PersonalAccessToken::findToken($response->json('data.token'));
    expect($token?->device_id)->toBe($this->device->id)
        ->and($token?->expires_at?->between(now()->addHours(15), now()->addHours(16)->addMinute()))->toBeTrue();

    freshAuth();
    $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me', apiHeaders())->assertOk();
});

it('validasi: PIN wajib 6 digit dan user_id UUID', function (array $body, string $field) {
    freshAuth();
    $response = $this->withToken($this->deviceToken)->postJson('/api/v1/auth/pin-login', $body, apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'PIN 5 digit' => [['user_id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a', 'pin' => '12345'], 'pin'],
    'PIN huruf' => [['user_id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a', 'pin' => 'abcdef'], 'pin'],
    'user_id rusak' => [['user_id' => "1' OR 1=1", 'pin' => '123456'], 'user_id'],
]);

it('PIN salah → 401 INVALID_PIN dan percobaan dihitung', function () {
    assertApiError(pinLogin($this, $this->cashier->id, '000000'), 'INVALID_PIN', 401);

    expect($this->cashier->refresh()->pin_failed_attempts)->toBe(1);
});

it('5 kali salah → PIN dikunci 15 menit (423 PIN_LOCKED), lalu bisa login lagi', function () {
    foreach (range(1, 4) as $_) {
        pinLogin($this, $this->cashier->id, '000000')->assertStatus(401);
        $this->app['cache']->store()->flush(); // kosongkan rate limiter, yang diuji di sini penguncian PIN
    }

    $locked = pinLogin($this, $this->cashier->id, '000000');
    assertApiError($locked, 'PIN_LOCKED', 423);
    expect($locked->json('error.details.retry_after'))->toBeGreaterThan(890)->toBeLessThanOrEqual(900);

    // PIN benar pun ditolak selama terkunci
    assertApiError(pinLogin($this, $this->cashier->id, '123456'), 'PIN_LOCKED', 423);

    $this->travel(16)->minutes();
    $this->app['cache']->store()->flush();

    pinLogin($this, $this->cashier->id, '123456')->assertOk();
    expect($this->cashier->refresh()->pin_failed_attempts)->toBe(0);
});

it('isolasi: karyawan tenant lain atau outlet lain tidak bisa login di device ini', function () {
    $foreign = User::factory()->cashier()->create();
    $otherOutlet = TenantContext::run($this->outlet->tenant_id, fn () => Outlet::factory()->create());
    $elsewhere = User::factory()->cashier()->forOutlet($otherOutlet)->create();

    assertApiError(pinLogin($this, $foreign->id, '123456'), 'INVALID_PIN', 401);
    $this->app['cache']->store()->flush();
    assertApiError(pinLogin($this, $elsewhere->id, '123456'), 'INVALID_PIN', 401);
});

it('karyawan nonaktif tidak bisa login PIN', function () {
    $this->cashier->update(['is_active' => false]);

    assertApiError(pinLogin($this, $this->cashier->id, '123456'), 'INVALID_PIN', 401);
});

it('menolak token user (harus device token)', function () {
    assertApiError(
        $this->withToken(userToken($this->cashier))
            ->postJson('/api/v1/auth/pin-login', ['user_id' => $this->cashier->id, 'pin' => '123456'], apiHeaders()),
        'DEVICE_NOT_REGISTERED',
        403,
    );
});

it('login PIN dua kali di device yang sama tetap satu token', function () {
    pinLogin($this, $this->cashier->id, '123456')->assertOk();
    pinLogin($this, $this->cashier->id, '123456')->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $this->cashier->id)->count())->toBe(1);
});

it('mencabut device memutus token kasir yang login di device itu', function () {
    $token = pinLogin($this, $this->cashier->id, '123456')->json('data.token');

    $this->device->forceFill(['revoked_at' => now()])->save();

    freshAuth();
    assertApiError($this->withToken($token)->getJson('/api/v1/auth/me', apiHeaders()), 'DEVICE_NOT_REGISTERED', 403);
});
