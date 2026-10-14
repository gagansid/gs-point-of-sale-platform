<?php

declare(strict_types=1);

use App\Enums\OpeningCashSource;
use App\Enums\ShiftStatus;
use App\Models\Device;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * Shift bersama per perangkat (ADR 0017, Q87–Q88): ganti kasir tanpa hitung (log operator), tutup oleh siapa saja
 * yang bertugas, setoran + carry-over kas awal, koreksi kas awal dengan izin/PIN approver.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
});

function sharedApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Shift yang sudah ditutup di device POS dengan sisa laci tertentu. */
function closedShiftWithFloat(stdClass $pos, ?string $float, ?Device $device = null): Shift
{
    return TenantContext::run($pos->tenantId, fn (): Shift => Shift::factory()->forDevice($device ?? $pos->device, $pos->cashier)->closed()
        ->create(['actual_cash' => '700000.00', 'cash_deposited' => $float === null ? null : '200000.00', 'closing_float' => $float]));
}

describe('setoran & carry-over kas awal', function () {
    it('tutup dengan disetor → sisa laci menjadi kas awal shift berikutnya tanpa diketik', function () {
        $id = sharedApi($this, 'POST', '/shifts', ['opening_cash' => 200000])->assertCreated()->json('data.id');

        sharedApi($this, 'POST', "/shifts/{$id}/close", ['actual_cash' => 650000, 'cash_deposited' => 450000])
            ->assertOk()
            ->assertJsonPath('data.shift.cash_deposited', '450000.00')
            ->assertJsonPath('data.shift.closing_float', '200000.00')
            ->assertJsonPath('data.shift.difference', '450000.00');

        sharedApi($this, 'GET', '/shifts/current')->assertJsonPath('data', null)->assertJsonPath('meta.carry_over_cash', '200000.00');

        sharedApi($this, 'POST', '/shifts')
            ->assertCreated()
            ->assertJsonPath('data.opening_cash', '200000.00')
            ->assertJsonPath('data.opening_cash_source', 'carry_over')
            ->assertJsonPath('data.carried_cash', '200000.00');

        expect(Shift::allTenants()->where('status', ShiftStatus::Open)->sole()->previous_shift_id)->toBe($id);
    });

    it('perangkat tanpa shift sebelumnya / shift lama tanpa sisa laci: kas awal wajib diketik', function () {
        assertApiError(sharedApi($this, 'POST', '/shifts'), 'VALIDATION_ERROR', 422);

        closedShiftWithFloat($this->pos, null);
        sharedApi($this, 'GET', '/shifts/current')->assertJsonPath('meta.carry_over_cash', null);
        assertApiError(sharedApi($this, 'POST', '/shifts'), 'VALIDATION_ERROR', 422);

        sharedApi($this, 'POST', '/shifts', ['opening_cash' => 100000])->assertCreated()->assertJsonPath('data.opening_cash_source', 'manual');
    });

    it('carry-over per perangkat: sisa laci perangkat lain tidak dipakai', function () {
        $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();
        closedShiftWithFloat($this->pos, '300000.00', $otherDevice);

        assertApiError(sharedApi($this, 'POST', '/shifts'), 'VALIDATION_ERROR', 422);
    });

    it('shift ditutup paksa tetap menjadi sumber carry-over', function () {
        $shift = Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create();

        $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();
        sharedApi($this, 'POST', "/shifts/{$shift->id}/force-close", ['actual_cash' => 250000, 'cash_deposited' => 100000, 'note' => 'Lupa tutup'], userToken($this->pos->supervisor, $otherDevice))
            ->assertOk()->assertJsonPath('data.shift.status', 'force_closed');

        sharedApi($this, 'POST', '/shifts')->assertCreated()->assertJsonPath('data.opening_cash', '150000.00');
    });

    it('disetor kosong = kas dihitung − modal laci tetap outlet (minimal 0); tanpa modal = 0', function (?string $float, int $actual, string $deposited, string $left) {
        $this->pos->outlet->forceFill(['cash_float' => $float])->save();
        $shift = Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create();

        sharedApi($this, 'POST', "/shifts/{$shift->id}/close", ['actual_cash' => $actual])
            ->assertOk()
            ->assertJsonPath('data.shift.cash_deposited', $deposited)
            ->assertJsonPath('data.shift.closing_float', $left);
    })->with([
        'modal 500rb, kas 800rb' => ['500000.00', 800000, '300000.00', '500000.00'],
        'modal 500rb, kas 300rb' => ['500000.00', 300000, '0.00', '300000.00'],
        'tanpa modal tetap' => [null, 800000, '0.00', '800000.00'],
    ]);

    it('validasi disetor', function (mixed $deposited) {
        $shift = Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create();

        assertApiError(sharedApi($this, 'POST', "/shifts/{$shift->id}/close", ['actual_cash' => 100000, 'cash_deposited' => $deposited]), 'VALIDATION_ERROR', 422);
        expect($shift->refresh()->status)->toBe(ShiftStatus::Open);
    })->with([['100000.01'], [-1], ['abc'], ['1.234']]);
});

describe('koreksi kas awal', function () {
    beforeEach(function () {
        closedShiftWithFloat($this->pos, '500000.00');
    });

    it('kasir membuka dengan angka lain: alasan wajib, lalu butuh PIN approver', function () {
        assertApiError(sharedApi($this, 'POST', '/shifts', ['opening_cash' => 480000]), 'VALIDATION_ERROR', 422);
        assertApiError(sharedApi($this, 'POST', '/shifts', ['opening_cash' => 480000, 'opening_cash_note' => 'Kurang 20rb']), 'APPROVAL_REQUIRED', 403);
        assertApiError(sharedApi($this, 'POST', '/shifts', [
            'opening_cash' => 480000, 'opening_cash_note' => 'x', 'approver_user_id' => $this->pos->cashier->id, 'approver_pin' => '123456',
        ]), 'SELF_APPROVAL_NOT_ALLOWED', 403);
        expect(Shift::allTenants()->where('status', ShiftStatus::Open)->exists())->toBeFalse();

        sharedApi($this, 'POST', '/shifts', [
            'opening_cash' => 480000, 'opening_cash_note' => 'Kurang 20rb', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ])->assertCreated()
            ->assertJsonPath('data.opening_cash', '480000.00')
            ->assertJsonPath('data.opening_cash_source', 'adjusted')
            ->assertJsonPath('data.carried_cash', '500000.00')
            ->assertJsonPath('data.opening_cash_note', 'Kurang 20rb');

        $shift = Shift::allTenants()->where('status', ShiftStatus::Open)->sole();
        expect($shift->opening_cash_adjusted_by)->toBe($this->pos->cashier->id)
            ->and($shift->opening_cash_approved_by)->toBe($this->pos->supervisor->id);
    });

    it('supervisor membuka dengan angka lain tanpa PIN; angka sama dengan carry-over = bukan koreksi', function () {
        sharedApi($this, 'POST', '/shifts', ['opening_cash' => 500000], userToken($this->pos->supervisor, $this->pos->device))
            ->assertCreated()->assertJsonPath('data.opening_cash_source', 'carry_over');

        $shift = Shift::allTenants()->where('status', ShiftStatus::Open)->sole();
        $shift->forceFill(['status' => ShiftStatus::Closed, 'open_device_key' => null, 'closed_at' => now(), 'closing_float' => '500000.00'])->save();

        sharedApi($this, 'POST', '/shifts', ['opening_cash' => 510000, 'opening_cash_note' => 'Tambah receh'], userToken($this->pos->supervisor, $this->pos->device))
            ->assertCreated()->assertJsonPath('data.opening_cash_source', 'adjusted');
    });

    it('koreksi saat shift berjalan: dari perangkat shift, alasan wajib, kasir dengan PIN approver', function () {
        $id = sharedApi($this, 'POST', '/shifts')->json('data.id');

        assertApiError(sharedApi($this, 'POST', "/shifts/{$id}/opening-cash", ['opening_cash' => 450000]), 'VALIDATION_ERROR', 422);
        assertApiError(sharedApi($this, 'POST', "/shifts/{$id}/opening-cash", ['opening_cash' => 450000, 'note' => 'Salah hitung']), 'APPROVAL_REQUIRED', 403);

        $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();
        assertApiError(
            sharedApi($this, 'POST', "/shifts/{$id}/opening-cash", ['opening_cash' => 450000, 'note' => 'x'], userToken($this->pos->supervisor, $otherDevice)),
            'FORBIDDEN',
            403,
        );

        sharedApi($this, 'POST', "/shifts/{$id}/opening-cash", [
            'opening_cash' => 450000, 'note' => 'Salah hitung', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ])->assertOk()
            ->assertJsonPath('data.opening_cash', '450000.00')
            ->assertJsonPath('data.carried_cash', '500000.00')
            ->assertJsonPath('data.opening_cash_source', OpeningCashSource::Adjusted->value);

        // Kas seharusnya mengikuti kas awal baru
        sharedApi($this, 'GET', "/shifts/{$id}/summary")->assertJsonPath('data.summary.expected_cash', '450000.00');
    });

    it('koreksi: shift sudah ditutup → SHIFT_NOT_OPEN; shift tenant lain → 404', function () {
        $closed = Shift::allTenants()->sole();
        assertApiError(sharedApi($this, 'POST', "/shifts/{$closed->id}/opening-cash", ['opening_cash' => 1, 'note' => 'x'], userToken($this->pos->supervisor, $this->pos->device)), 'SHIFT_NOT_OPEN', 409);

        $foreign = posSetup();
        assertApiError(sharedApi($this, 'POST', "/shifts/{$foreign->shift->id}/opening-cash", ['opening_cash' => 1, 'note' => 'x']), 'NOT_FOUND', 404);
        expect($foreign->shift->refresh()->opening_cash)->toBe('200000.00');
    });
});

describe('ganti kasir tanpa hitung (log operator)', function () {
    it('buka → login PIN kasir lain → login ulang orang sama tidak menambah baris → tutup mengakhiri log', function () {
        $id = sharedApi($this, 'POST', '/shifts', ['opening_cash' => 100000])->json('data.id');
        $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create(['name' => 'Ani']);
        $deviceToken = deviceToken($this->pos->device);

        foreach ([$other, $other] as $user) {
            sharedApi($this, 'POST', '/auth/pin-login', ['user_id' => $user->id, 'pin' => '123456'], $deviceToken)->assertOk();
        }

        $rows = ShiftOperator::allTenants()->where('shift_id', $id)->orderBy('started_at')->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->user_id)->toBe($this->pos->cashier->id)
            ->and($rows[0]->ended_at)->not->toBeNull()
            ->and($rows[1]->user_id)->toBe($other->id)
            ->and($rows[1]->ended_at)->toBeNull();

        // Kasir pengganti menutup biasa; rekap terlihat olehnya
        $otherToken = userToken($other, $this->pos->device);
        sharedApi($this, 'GET', "/shifts/{$id}/summary", token: $otherToken)->assertOk()->assertJsonCount(2, 'data.shift.operators');
        sharedApi($this, 'POST', "/shifts/{$id}/close", ['actual_cash' => 100000], $otherToken)->assertOk();

        expect(ShiftOperator::allTenants()->where('shift_id', $id)->whereNull('ended_at')->exists())->toBeFalse();
    });

    it('login PIN tanpa shift terbuka tidak mencatat apa pun', function () {
        sharedApi($this, 'POST', '/auth/pin-login', ['user_id' => $this->pos->cashier->id, 'pin' => '123456'], deviceToken($this->pos->device))->assertOk();

        expect(ShiftOperator::allTenants()->count())->toBe(0);
    });

    it('owner login email di device kasir menjadi operator', function () {
        $id = sharedApi($this, 'POST', '/shifts', ['opening_cash' => 0])->json('data.id');
        $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId, 'email' => 'owner@kopi.test']);

        sharedApi($this, 'POST', '/auth/login', ['email' => 'owner@kopi.test', 'password' => 'password', 'device_uid' => 'kasir-1'])->assertOk();

        expect(ShiftOperator::allTenants()->where('shift_id', $id)->whereNull('ended_at')->value('user_id'))->toBe($owner->id);
    });

    it('kasir pengganti melihat order kasir sebelumnya di shift yang berjalan, tidak di shift device lain', function () {
        sharedApi($this, 'POST', '/shifts', ['opening_cash' => 100000]);
        $orderId = sharedApi($this, 'POST', '/checkout', [
            'id' => uuid(), 'order_type' => 'takeaway',
            'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
            'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 30000]],
        ])->assertCreated()->json('data.id');

        $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
        sharedApi($this, 'GET', "/orders/{$orderId}", token: userToken($other, $this->pos->device))->assertOk();

        $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();
        assertApiError(sharedApi($this, 'GET', "/orders/{$orderId}", token: userToken($other, $otherDevice)), 'FORBIDDEN', 403);
    });

    it('ringkasan shift: kasir yang tidak pernah bertugas di shift itu ditolak', function () {
        $id = sharedApi($this, 'POST', '/shifts', ['opening_cash' => 0])->json('data.id');
        $stranger = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
        $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();

        assertApiError(sharedApi($this, 'GET', "/shifts/{$id}/summary", token: userToken($stranger, $otherDevice)), 'FORBIDDEN', 403);
    });
});
