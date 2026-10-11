<?php

declare(strict_types=1);

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** Shift kasir web: buka, X-report, tutup / tutup paksa, Z-report & PDF (SPEC Q54). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    webCashierLogin($this, $this->pos, $this->pos->cashier);
});

function webShifts(): Collection
{
    return Shift::allTenants()->get();
}

it('buka shift dengan kas awal → terikat device browser; buka ulang = shift yang sama', function () {
    $this->post(route('cashier.shift.store'), ['opening_cash' => '150000'])->assertRedirect(route('cashier.home'));
    $this->post(route('cashier.shift.store'), ['opening_cash' => '150000'])->assertRedirect(route('cashier.home'));

    $shift = webShifts()->sole();
    expect($shift->device_id)->toBe($this->pos->webDevice->id)
        ->and($shift->opening_cash)->toBe('150000.00')
        ->and($shift->opened_by)->toBe($this->pos->cashier->id);

    $this->get(route('cashier.home'))->assertOk();
    $this->get(route('cashier.shift'))->assertOk()->assertSee('X-report')->assertSee('Rp150.000');
});

it('validasi kas awal', function (mixed $value) {
    $this->post(route('cashier.shift.store'), ['opening_cash' => $value])->assertSessionHasErrors('opening_cash');
    expect(webShifts())->toBeEmpty();
})->with(['kosong' => [''], 'negatif' => ['-1'], 'teks' => ['abc'], '3 desimal' => ['1.234']]);

it('shift tablet di outlet yang sama tidak dipakai kasir web (shift per device)', function () {
    TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create());

    $this->get(route('cashier.home'))->assertRedirect(route('cashier.shift'));
});

it('tutup shift: selisih kas tercatat, lalu Z-report', function () {
    $this->post(route('cashier.shift.store'), ['opening_cash' => '100000']);
    $shift = webShifts()->sole();

    $this->post(route('cashier.shift.close'), ['actual_cash' => '95000', 'note' => 'Kurang 5rb'])
        ->assertRedirect(route('cashier.shifts.show', $shift));

    $shift->refresh();
    expect($shift->status)->toBe(ShiftStatus::Closed)
        ->and($shift->difference)->toBe('-5000.00')
        ->and($shift->close_note)->toBe('Kurang 5rb');

    $this->get(route('cashier.shifts.show', $shift))->assertOk()->assertSee('Z-report')->assertSee('Buka shift baru');
    $this->get(route('cashier.home'))->assertRedirect(route('cashier.shift'));
    $this->post(route('cashier.shift.close'), ['actual_cash' => '1'])->assertSessionHasErrors('cashier');
});

it('shift dibuka orang lain di browser ini: kasir tidak bisa tutup paksa, supervisor bisa dengan catatan', function () {
    $this->post(route('cashier.shift.store'), ['opening_cash' => '100000']);
    $shift = webShifts()->sole();

    // Orang lain memakai browser yang sama (device sama)
    $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create(['tenant_id' => $this->pos->tenantId]);
    Auth::forgetGuards();
    $this->actingAs($other, 'cashier')->withSession(['cashier.password_hash' => WebCashier::passwordFingerprint($other)]);
    $other->forceFill(['web_cashier_access' => true])->save();
    $this->post(route('cashier.shift.close'), ['actual_cash' => '100000', 'note' => 'x'])->assertForbidden();

    $sari = $this->pos->supervisor;
    $sari->forceFill(['web_cashier_access' => true])->save();
    Auth::forgetGuards();
    $this->actingAs($sari, 'cashier')->withSession(['cashier.password_hash' => WebCashier::passwordFingerprint($sari)]);
    $this->get(route('cashier.shift'))->assertOk()->assertSee('Tutup paksa shift');
    $this->post(route('cashier.shift.close'), ['actual_cash' => '100000'])->assertSessionHasErrors('note');
    $this->post(route('cashier.shift.close'), ['actual_cash' => '100000', 'note' => 'Budi pulang'])->assertRedirect();

    expect($shift->fresh()?->status)->toBe(ShiftStatus::ForceClosed)
        ->and($shift->fresh()?->closed_by)->toBe($sari->id);
});

it('rekap & PDF shift: shift tenant lain 404, kasir lain di outlet ditolak', function () {
    $this->post(route('cashier.shift.store'), ['opening_cash' => '100000']);
    $shift = webShifts()->sole();

    $this->get(route('cashier.shifts.pdf', $shift))->assertOk()->assertHeader('content-type', 'application/pdf');

    $foreign = posSetup();
    $this->get(route('cashier.shifts.show', $foreign->shift))->assertNotFound();
    $this->get(route('cashier.shifts.pdf', $foreign->shift))->assertNotFound();
});

it('riwayat: kosong tanpa transaksi', function () {
    $this->get(route('cashier.orders'))->assertOk()->assertSee('Belum ada shift terbuka');
    $this->post(route('cashier.shift.store'), ['opening_cash' => '0']);
    $this->get(route('cashier.orders'))->assertOk()->assertSee('Belum ada transaksi');
});
