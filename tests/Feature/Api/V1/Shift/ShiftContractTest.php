<?php

declare(strict_types=1);

use App\Enums\OpeningCashSource;
use App\Enums\ShiftStatus;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Kontrak shift (fase 2): tutup paksa lintas tenant & ganda, koreksi kas awal ganda,
 * dan GET /shifts/current untuk token tanpa device / device outlet lain / tenant lain.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 03:00:00');
    $this->pos = posSetup();
});

afterEach(fn () => Carbon::setTestNow());

function contractApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

describe('POST /shifts/{id}/force-close', function () {
    it('menolak tutup paksa shift tenant lain dengan 404 dan shift tetap terbuka', function () {
        $foreign = posSetup();

        assertApiError(
            contractApi($this, 'POST', "/shifts/{$foreign->shift->id}/force-close", ['actual_cash' => 0, 'note' => 'Iseng'], userToken($this->pos->supervisor, $this->pos->device)),
            'NOT_FOUND',
            404,
        );

        $shift = Shift::allTenants()->findOrFail($foreign->shift->id);
        expect($shift->status)->toBe(ShiftStatus::Open)
            ->and($shift->closed_by)->toBeNull()
            ->and($shift->open_device_key)->toBe($foreign->device->id);
    });

    it('tutup paksa dikirim dua kali → shift ditutup sekali; replay tidak menghitung ulang', function () {
        $shiftId = $this->pos->shift->id;
        $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();

        contractApi($this, 'POST', "/shifts/{$shiftId}/force-close", ['actual_cash' => 190000, 'note' => 'Kasir lupa tutup'], userToken($this->pos->supervisor, $otherDevice))
            ->assertOk()
            ->assertJsonPath('data.shift.status', 'force_closed')
            ->assertJsonPath('data.shift.expected_cash', '200000.00')
            ->assertJsonPath('data.shift.difference', '-10000.00')
            ->assertJsonPath('meta.idempotent_replay', false);

        $first = Shift::allTenants()->findOrFail($shiftId);

        // Request kedua: waktu bergeser, pelaku lain, angka lain
        Carbon::setTestNow('2026-10-08 05:00:00');
        $manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
        contractApi($this, 'POST', "/shifts/{$shiftId}/force-close", ['actual_cash' => 1, 'cash_deposited' => 1, 'note' => 'Ulang'], userToken($manager, $otherDevice))
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.shift.status', 'force_closed')
            ->assertJsonPath('data.shift.actual_cash', '190000.00')
            ->assertJsonPath('data.shift.difference', '-10000.00')
            ->assertJsonPath('data.shift.closed_by', $this->pos->supervisor->id);

        $after = Shift::allTenants()->findOrFail($shiftId);
        expect($after->closed_by)->toBe($this->pos->supervisor->id)
            ->and($after->closed_at->equalTo($first->closed_at))->toBeTrue()
            ->and($after->expected_cash)->toBe($first->expected_cash)
            ->and($after->difference)->toBe($first->difference)
            ->and($after->cash_deposited)->toBe($first->cash_deposited)
            ->and($after->close_note)->toBe('Kasir lupa tutup')
            ->and(Shift::allTenants()->count())->toBe(1);
    });
});

describe('POST /shifts/{id}/opening-cash', function () {
    it('koreksi ganda dengan payload sama → satu koreksi, kas awal tidak berubah dua kali', function () {
        $shiftId = $this->pos->shift->id;
        $token = userToken($this->pos->supervisor, $this->pos->device);
        $payload = ['opening_cash' => 150000, 'note' => 'Salah hitung'];

        contractApi($this, 'POST', "/shifts/{$shiftId}/opening-cash", $payload, $token)
            ->assertOk()
            ->assertJsonPath('data.opening_cash', '150000.00')
            ->assertJsonPath('data.carried_cash', '200000.00')
            ->assertJsonPath('data.opening_cash_source', OpeningCashSource::Adjusted->value);

        $first = Shift::allTenants()->findOrFail($shiftId);

        Carbon::setTestNow('2026-10-08 04:00:00');
        contractApi($this, 'POST', "/shifts/{$shiftId}/opening-cash", $payload, $token)
            ->assertOk()
            ->assertJsonPath('data.opening_cash', '150000.00')
            ->assertJsonPath('data.carried_cash', '200000.00');

        $after = Shift::allTenants()->findOrFail($shiftId);
        expect($after->opening_cash)->toBe('150000.00')
            // Nilai semula tetap kas awal pertama, bukan hasil koreksi pertama
            ->and($after->carried_cash)->toBe('200000.00')
            ->and($after->opening_cash_adjusted_at->equalTo($first->opening_cash_adjusted_at))->toBeTrue()
            ->and($after->opening_cash_adjusted_by)->toBe($this->pos->supervisor->id);

        contractApi($this, 'GET', "/shifts/{$shiftId}/summary", token: $token)->assertJsonPath('data.summary.expected_cash', '150000.00');
    });
});

describe('GET /shifts/current', function () {
    it('menolak token user tanpa device kasir terdaftar dengan DEVICE_NOT_REGISTERED', function () {
        $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

        assertApiError(contractApi($this, 'GET', '/shifts/current', token: userToken($owner)), 'DEVICE_NOT_REGISTERED', 403);
    });

    it('menolak token dari device yang sudah dicabut dengan DEVICE_NOT_REGISTERED', function () {
        $this->pos->device->forceFill(['revoked_at' => now()])->save();

        assertApiError(contractApi($this, 'GET', '/shifts/current'), 'DEVICE_NOT_REGISTERED', 403);
    });

    it('menolak kasir yang login di device outlet lain (tidak ditugaskan) tanpa membocorkan shift', function () {
        $otherOutlet = Outlet::factory()->create(['tenant_id' => $this->pos->tenantId]);
        $otherDevice = Device::factory()->forOutlet($otherOutlet)->create();
        $otherShift = Shift::factory()->forDevice($otherDevice)->create();

        $response = contractApi($this, 'GET', '/shifts/current', token: userToken($this->pos->cashier, $otherDevice));

        assertApiError($response, 'FORBIDDEN', 403);
        $response->assertDontSee($otherShift->id);
    });

    it('menolak token yang terikat device tenant lain tanpa membocorkan shift tenant itu', function () {
        $foreign = posSetup();

        $response = contractApi($this, 'GET', '/shifts/current', token: userToken($this->pos->cashier, $foreign->device));

        assertApiError($response, 'FORBIDDEN', 403);
        $response->assertDontSee($foreign->shift->id);
    });

    it('tidak pernah mengembalikan shift atau sisa laci tenant lain walau terkait device ini', function () {
        $foreign = posSetup();
        $device = $this->pos->device;

        // Tutup shift tenant A, lalu data tenant B dipalsukan menunjuk device tenant A
        Shift::allTenants()->whereKey($this->pos->shift->id)->update(['status' => ShiftStatus::Closed, 'open_device_key' => null, 'closed_at' => now(), 'closing_float' => null]);
        Shift::allTenants()->whereKey($foreign->shift->id)->update(['device_id' => $device->id, 'open_device_key' => $device->id]);
        Shift::factory()->forDevice($foreign->device)->closed()->create(['device_id' => $device->id, 'closing_float' => '777000.00', 'closed_at' => now()->addMinute()]);

        contractApi($this, 'GET', '/shifts/current')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.carry_over_cash', null)
            ->assertDontSee($foreign->shift->id);
    });
});
