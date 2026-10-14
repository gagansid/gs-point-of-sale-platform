<?php

declare(strict_types=1);

use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\WebCashier;

/**
 * Kasir web, shift bersama (ADR 0017): kunci layar & ganti kasir dengan PIN, carry-over kas awal, disetor,
 * kas masuk/keluar, koreksi kas awal.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    webCashierLogin($this, $this->pos, $this->pos->cashier);
    $this->ani = User::factory()->cashier()->forOutlet($this->pos->outlet)->create(['name' => 'Ani', 'web_cashier_access' => true]);
});

function webOpenShift(object $test, string $cash = '100000'): Shift
{
    $test->post(route('cashier.shift.store'), ['opening_cash' => $cash])->assertRedirect(route('cashier.home'));

    return Shift::allTenants()->where('open_device_key', $test->pos->webDevice->id)->sole();
}

describe('kunci layar & ganti kasir', function () {
    it('Kunci → semua halaman diarahkan ke layar PIN → kasir lain masuk dengan PIN, shift sama berlanjut', function () {
        $shift = webOpenShift($this);

        $this->post(route('cashier.lock'))->assertRedirect(route('cashier.unlock'));
        $this->get(route('cashier.home'))->assertRedirect(route('cashier.unlock'));
        $this->post(route('cashier.shift.close'), ['actual_cash' => '1'])->assertRedirect(route('cashier.unlock'));
        $this->get(route('cashier.unlock'))->assertOk()->assertSee('Layar terkunci')->assertSee('Ani')->assertSee('Budi');

        $this->post(route('cashier.unlock.store'), ['user_id' => $this->ani->id, 'pin' => '123456'])
            ->assertRedirect(route('cashier.home'));

        expect(WebCashier::user()?->id)->toBe($this->ani->id)
            ->and(session(WebCashier::SESSION_DEVICE))->toBe($this->pos->webDevice->id);
        $this->get(route('cashier.home'))->assertOk();

        $operators = ShiftOperator::allTenants()->where('shift_id', $shift->id)->orderBy('started_at')->orderBy('id')->pluck('user_id')->all();
        expect($operators)->toBe([$this->pos->cashier->id, $this->ani->id]);
        expect(Shift::allTenants()->count())->toBe(1);
    });

    it('PIN salah / karyawan outlet lain / tanpa akses kasir web ditolak dan tetap terkunci', function () {
        webOpenShift($this);
        $this->post(route('cashier.lock'));

        $this->post(route('cashier.unlock.store'), ['user_id' => $this->ani->id, 'pin' => '000000'])->assertSessionHasErrors('pin');

        $foreign = posSetup();
        $outsider = TenantContext::run($foreign->tenantId, fn () => User::factory()->cashier()->forOutlet($foreign->outlet)->create(['web_cashier_access' => true]));
        $this->post(route('cashier.unlock.store'), ['user_id' => $outsider->id, 'pin' => '123456'])->assertSessionHasErrors('pin');

        $noWeb = User::factory()->cashier()->forOutlet($this->pos->outlet)->create(['web_cashier_access' => false]);
        $this->post(route('cashier.unlock.store'), ['user_id' => $noWeb->id, 'pin' => '123456'])->assertSessionHasErrors('pin');

        $this->post(route('cashier.unlock.store'), ['user_id' => 'bukan-uuid', 'pin' => '12'])->assertSessionHasErrors(['user_id', 'pin']);

        expect(WebCashier::user()?->id)->toBe($this->pos->cashier->id);
        $this->get(route('cashier.home'))->assertRedirect(route('cashier.unlock'));
    });

    it('tanpa terkunci, halaman buka kunci kembali ke beranda; kunci otomatis memakai setelan outlet', function () {
        webOpenShift($this);
        $this->get(route('cashier.unlock'))->assertRedirect(route('cashier.home'));

        $this->pos->outlet->forceFill(['auto_lock_minutes' => 7])->save();
        $this->get(route('cashier.shift'))->assertOk()->assertSee('const minutes = 7', false)->assertSee('Kunci');
    });
});

describe('buka & tutup shift dengan carry-over', function () {
    it('tutup dengan disetor → buka berikutnya tanpa mengetik kas awal', function () {
        $shift = webOpenShift($this, '200000');

        $this->get(route('cashier.shift'))->assertOk()->assertSee('Disetor');
        $this->post(route('cashier.shift.close'), ['actual_cash' => '650000', 'cash_deposited' => '450000'])
            ->assertRedirect(route('cashier.shifts.show', $shift));
        expect($shift->refresh()->closing_float)->toBe('200000.00');

        $this->get(route('cashier.shifts.show', $shift))->assertOk()->assertSee('Disetor')->assertSee('Rp450.000')->assertSee('Ditinggal di laci');
        $this->get(route('cashier.shift'))->assertOk()->assertSee('sisa laci shift sebelumnya')->assertSee('Rp200.000');

        $this->post(route('cashier.shift.store'))->assertRedirect(route('cashier.home'));
        $next = Shift::allTenants()->where('open_device_key', $this->pos->webDevice->id)->sole();
        expect($next->opening_cash)->toBe('200000.00')->and($next->opening_cash_source->value)->toBe('carry_over');
    });

    it('disetor melebihi kas dihitung ditolak', function () {
        webOpenShift($this);

        $this->post(route('cashier.shift.close'), ['actual_cash' => '100000', 'cash_deposited' => '100001'])->assertSessionHasErrors('cash_deposited');
    });

    it('kas awal berbeda dari carry-over: kasir butuh PIN supervisor & alasan', function () {
        $shift = webOpenShift($this, '200000');
        $this->post(route('cashier.shift.close'), ['actual_cash' => '200000']);

        $this->post(route('cashier.shift.store'), ['opening_cash' => '150000'])->assertSessionHasErrors('cashier');
        $this->post(route('cashier.shift.store'), ['opening_cash' => '150000', 'opening_cash_note' => 'Diambil owner'])->assertSessionHasErrors('cashier');
        expect(Shift::allTenants()->where('open_device_key', $this->pos->webDevice->id)->exists())->toBeFalse();

        $this->post(route('cashier.shift.store'), [
            'opening_cash' => '150000', 'opening_cash_note' => 'Diambil owner',
            'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ])->assertRedirect(route('cashier.home'));

        $next = Shift::allTenants()->whereKeyNot($shift->id)->sole();
        expect($next->opening_cash)->toBe('150000.00')->and($next->opening_cash_approved_by)->toBe($this->pos->supervisor->id);
        $this->get(route('cashier.shift'))->assertOk()->assertSee('Dikoreksi dari');
    });
});

describe('kas masuk/keluar & koreksi kas awal', function () {
    it('catat kas keluar: masuk ke X-report; klik ganda dengan id sama tidak mencatat dua kali', function () {
        webOpenShift($this, '100000');
        $id = (string) Str::uuid7();
        $body = ['id' => $id, 'type' => 'out', 'reason' => 'operational', 'amount' => '20000', 'note' => 'Galon'];

        $this->post(route('cashier.shift.cash-movements.store'), $body)->assertRedirect(route('cashier.shift'))->assertSessionHas('status', 'Kas keluar Rp20.000 dicatat');
        $this->post(route('cashier.shift.cash-movements.store'), $body)->assertSessionHas('status', 'Kas sudah tercatat');

        expect(CashMovement::allTenants()->count())->toBe(1);
        $this->get(route('cashier.shift'))->assertOk()->assertSee('Kas keluar')->assertSee('Galon')->assertSee('Rp80.000');
    });

    it('validasi & approval kas keluar di atas batas', function () {
        webOpenShift($this, '500000');

        $this->post(route('cashier.shift.cash-movements.store'), ['id' => (string) Str::uuid7(), 'type' => 'out', 'reason' => 'other', 'amount' => '1000'])
            ->assertSessionHasErrors('note');
        $this->post(route('cashier.shift.cash-movements.store'), ['id' => (string) Str::uuid7(), 'type' => 'out', 'reason' => 'operational', 'amount' => '150000'])
            ->assertSessionHasErrors('cashier');
        $this->post(route('cashier.shift.cash-movements.store'), [
            'id' => (string) Str::uuid7(), 'type' => 'out', 'reason' => 'operational', 'amount' => '150000',
            'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ])->assertRedirect(route('cashier.shift'));

        expect(CashMovement::allTenants()->sole()->approved_by)->toBe($this->pos->supervisor->id);
    });

    it('koreksi kas awal shift berjalan dengan PIN supervisor', function () {
        $shift = webOpenShift($this, '100000');

        $this->post(route('cashier.shift.opening-cash'), ['opening_cash' => '90000'])->assertSessionHasErrors('opening_cash_note');
        $this->post(route('cashier.shift.opening-cash'), [
            'opening_cash' => '90000', 'opening_cash_note' => 'Salah hitung',
            'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ])->assertRedirect(route('cashier.shift'));

        expect($shift->refresh()->opening_cash)->toBe('90000.00')->and($shift->carried_cash)->toBe('100000.00');
    });
});
