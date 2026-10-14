<?php

declare(strict_types=1);

use App\Models\CashMovement;
use App\Models\Device;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** Kas masuk/keluar shift (ADR 0017, Q89): kas seharusnya, approval kas keluar, idempotensi, isolasi. */
beforeEach(function () {
    // Shift terbuka dengan kas awal Rp200.000; batas kas keluar tanpa PIN Rp100.000 (bawaan)
    $this->pos = posSetup();
});

function cashMove(object $test, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->postJson('/api/v1/cash-movements', [
        'id' => uuid(), 'type' => 'out', 'reason' => 'operational', 'amount' => 20000, 'note' => 'Beli galon', ...$body,
    ], apiHeaders());
}

function shiftSummary(object $test): TestResponse
{
    freshAuth();

    return $test->withToken($test->pos->token)->getJson("/api/v1/shifts/{$test->pos->shift->id}/summary", apiHeaders());
}

it('kas keluar & masuk masuk ke kas seharusnya dan rekap shift', function () {
    cashMove($this)->assertCreated()
        ->assertJsonPath('message', 'Kas berhasil dicatat')
        ->assertJsonPath('data.type', 'out')
        ->assertJsonPath('data.reason_label', 'Belanja operasional')
        ->assertJsonPath('data.amount', '20000.00')
        ->assertJsonPath('data.user_name', 'Budi')
        ->assertJsonPath('data.approved_by', null);
    cashMove($this, ['type' => 'in', 'reason' => 'change', 'amount' => 50000, 'note' => null])->assertCreated();

    shiftSummary($this)
        ->assertJsonPath('data.summary.cash_in', '50000.00')
        ->assertJsonPath('data.summary.cash_out', '20000.00')
        ->assertJsonPath('data.summary.expected_cash', '230000.00');

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$this->pos->shift->id}/cash-movements", apiHeaders())
        ->assertOk()->assertJsonCount(2, 'data');

    // Tutup: selisih memperhitungkan kas masuk/keluar
    freshAuth();
    $this->withToken($this->pos->token)->postJson("/api/v1/shifts/{$this->pos->shift->id}/close", ['actual_cash' => 230000], apiHeaders())
        ->assertOk()->assertJsonPath('data.shift.difference', '0.00');
});

it('request ganda dengan id sama → satu catatan', function () {
    $id = uuid();
    cashMove($this, ['id' => $id])->assertCreated();
    cashMove($this, ['id' => $id, 'amount' => 99999])->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.amount', '20000.00');

    expect(CashMovement::allTenants()->count())->toBe(1);
});

it('validasi', function (array $body) {
    assertApiError(cashMove($this, $body), 'VALIDATION_ERROR', 422);
    expect(CashMovement::allTenants()->count())->toBe(0);
})->with([
    'alasan khusus keluar untuk kas masuk' => [['type' => 'in', 'reason' => 'operational']],
    'setor ke owner untuk kas masuk' => [['type' => 'in', 'reason' => 'deposit']],
    'lainnya tanpa catatan' => [['reason' => 'other', 'note' => null]],
    'nominal 0' => [['amount' => 0]],
    'nominal 3 desimal' => [['amount' => '1.234']],
    'jenis tidak dikenal' => [['type' => 'transfer']],
    'id bukan uuid' => [['id' => 'abc']],
    'catatan terlalu panjang' => [['note' => str_repeat('a', 256)]],
]);

it('kas keluar melebihi uang di laci ditolak', function () {
    assertApiError(cashMove($this, ['amount' => 200000.01, 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456']), 'VALIDATION_ERROR', 422);
});

describe('approval kas keluar di atas batas', function () {
    it('kasir tanpa PIN → APPROVAL_REQUIRED dengan batas; dengan PIN supervisor tercatat approver', function () {
        $response = cashMove($this, ['amount' => 150000]);
        assertApiError($response, 'APPROVAL_REQUIRED', 403);
        $response->assertJsonPath('error.details.limit', '100000.00');

        assertApiError(cashMove($this, ['amount' => 150000, 'approver_user_id' => $this->pos->cashier->id, 'approver_pin' => '123456']), 'SELF_APPROVAL_NOT_ALLOWED', 403);
        assertApiError(cashMove($this, ['amount' => 150000, 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '000000']), 'INVALID_PIN', 401);

        cashMove($this, ['amount' => 150000, 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456'])
            ->assertCreated()->assertJsonPath('data.approved_by', $this->pos->supervisor->id);
    });

    it('tepat di batas & kas masuk tidak butuh PIN; supervisor tidak butuh PIN; batas kosong = tanpa approval', function () {
        cashMove($this, ['amount' => 100000])->assertCreated();
        cashMove($this, ['type' => 'in', 'reason' => 'change', 'amount' => 500000])->assertCreated();
        cashMove($this, ['amount' => 150000], userToken($this->pos->supervisor, $this->pos->device))->assertCreated()->assertJsonPath('data.approved_by', null);

        $this->pos->outlet->forceFill(['cash_out_approval_limit' => null])->save();
        cashMove($this, ['amount' => 150000])->assertCreated();
    });
});

it('tanpa shift terbuka di perangkat → SHIFT_NOT_OPEN', function () {
    $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();

    assertApiError(cashMove($this, token: userToken($this->pos->cashier, $otherDevice)), 'SHIFT_NOT_OPEN', 409);
});

it('isolasi tenant: id milik tenant lain → 404, daftar kas shift tenant lain → 404', function () {
    $foreign = posSetup();
    $foreignId = uuid();
    freshAuth();
    $this->withToken($foreign->token)->postJson('/api/v1/cash-movements', ['id' => $foreignId, 'type' => 'in', 'reason' => 'change', 'amount' => 1000], apiHeaders())->assertCreated();

    assertApiError(cashMove($this, ['id' => $foreignId]), 'NOT_FOUND', 404);

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$foreign->shift->id}/cash-movements", apiHeaders()), 'NOT_FOUND', 404);
    expect(CashMovement::allTenants()->count())->toBe(1);
});

it('daftar kas: kasir yang tidak pernah bertugas di shift itu ditolak', function () {
    $stranger = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
    $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();

    freshAuth();
    assertApiError(
        $this->withToken(userToken($stranger, $otherDevice))->getJson("/api/v1/shifts/{$this->pos->shift->id}/cash-movements", apiHeaders()),
        'FORBIDDEN',
        403,
    );
});
