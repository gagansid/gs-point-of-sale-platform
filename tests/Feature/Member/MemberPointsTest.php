<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\ExpireMemberPoints;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Enums\PointEntryType;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Members\Pages\ManageMembers;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Poin member M2 (ADR 0013, Q62). Croissant 24.000 → subtotal 24.000 (dasar poin), total 28.000 setelah
 * service 5% & pajak 11% (pembulatan Rp100). Aturan bawaan: Rp10.000 = 1 poin, berlaku 12 bulan.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->member = Member::factory()->create(['name' => 'Rina']);
    $this->owner = User::factory()->owner()->create();
});

afterEach(fn () => Carbon::setTestNow());

function pointSale(object $test, int $qty = 1, array $override = []): Order
{
    return app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 200000]],
        ...$override,
    ]))['order'];
}

it('order selesai: poin dari subtotal setelah diskon (bukan pajak/service), statistik member naik', function () {
    // 3 × 24.000 = 72.000 → 7 poin (bukan dari total 84.000)
    $order = pointSale($this, 3);

    $member = $this->member->refresh();
    expect($order->points_earned)->toBe(7)
        ->and($member->points_balance)->toBe(7)
        ->and($member->visit_count)->toBe(1)
        ->and($member->total_spent)->toBe($order->grand_total)
        ->and($member->last_visit_at)->not->toBeNull();

    $entry = MemberPointEntry::query()->sole();
    expect($entry->type)->toBe(PointEntryType::Earn)
        ->and($entry->order_id)->toBe($order->id)
        ->and($entry->expires_at?->toDateString())->toBe(now()->addMonths(12)->toDateString());
});

it('diskon order mengurangi dasar poin', function () {
    // 72.000 − 10% = 64.800 → 6 poin
    expect(pointSale($this, 3, ['discount' => ['type' => 'percent', 'value' => 10]])->points_earned)->toBe(6);
});

it('aturan poin bisa diubah owner; berlaku untuk transaksi berikutnya', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id], ['earn_amount' => '5000', 'earn_points' => '2']);

    // 24.000 / 5.000 = 4 × 2 poin
    expect(pointSale($this)->points_earned)->toBe(8);
});

it('outlet dikecualikan / member nonaktif: kunjungan tercatat, tanpa poin', function () {
    app(UpdateMemberProgram::class)->handle(true, []);
    $order = pointSale($this);

    expect($order->points_earned)->toBe(0)
        ->and($this->member->refresh()->visit_count)->toBe(1)
        ->and(MemberPointEntry::query()->count())->toBe(0);
});

it('void: poin ditarik, statistik kembali; void ulang tidak menarik dua kali', function () {
    $order = pointSale($this, 3);
    app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Batal', null);
    app(VoidOrder::class)->handle($this->pos->supervisor, $order->refresh(), 'Batal', null);

    $member = $this->member->refresh();
    expect($member->points_balance)->toBe(0)
        ->and($member->visit_count)->toBe(0)
        ->and($member->total_spent)->toBe('0.00')
        ->and(MemberPointEntry::query()->where('type', PointEntryType::VoidReversal)->sole()->points)->toBe(-7);
});

it('void setelah poin terpakai: saldo boleh minus (tercatat jujur)', function () {
    $order = pointSale($this, 3);
    app(AdjustMemberPoints::class)->handle($this->member, -5, 'Tukar hadiah', $this->owner);
    app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Batal', null);

    expect($this->member->refresh()->points_balance)->toBe(-5);
});

it('penyesuaian manual: alasan wajib, tidak boleh melebihi saldo', function () {
    app(AdjustMemberPoints::class)->handle($this->member, 20, 'Kompensasi', $this->owner);

    expect(fn () => app(AdjustMemberPoints::class)->handle($this->member, -21, 'Koreksi', $this->owner))->toThrow(BusinessException::class, 'melebihi saldo')
        ->and(fn () => app(AdjustMemberPoints::class)->handle($this->member, 5, ' ', $this->owner))->toThrow(BusinessException::class, 'Alasan wajib')
        ->and($this->member->refresh()->points_balance)->toBe(20);
});

it('kedaluwarsa FIFO: hanya kredit yang lewat masa berlaku, sisa terpakai ikut diperhitungkan', function () {
    Carbon::setTestNow('2026-01-10 10:00:00');
    pointSale($this, 3);                       // 7 poin, berlaku s/d 10 Jan 2027
    Carbon::setTestNow('2026-06-10 10:00:00');
    pointSale($this, 5);                       // 12 poin, berlaku s/d 10 Jun 2027
    app(AdjustMemberPoints::class)->handle($this->member, -4, 'Tukar', $this->owner);   // memakai 4 dari kredit pertama

    Carbon::setTestNow('2027-01-11 00:00:00');
    expect(app(ExpireMemberPoints::class)->handle(now()->toImmutable()))->toBe(3);
    expect(app(ExpireMemberPoints::class)->handle(now()->toImmutable()))->toBe(0);

    expect($this->member->refresh()->points_balance)->toBe(12);
});

it('API: riwayat poin & poin di order serta struk', function () {
    $order = pointSale($this, 3);
    freshAuth();

    $this->withToken($this->pos->token)->getJson("/api/v1/members/{$this->member->id}/points", apiHeaders())
        ->assertOk()->assertJsonPath('data.balance', 7)->assertJsonPath('data.entries.0.type', 'earn');

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$order->id}/receipt", apiHeaders())
        ->assertOk()->assertJsonPath('data.member_points.earned', 7)->assertJsonPath('data.member_points.balance', 7);
});

it('dashboard: sesuaikan poin hanya member.points_adjust; riwayat tampil', function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    Livewire::test(ManageMembers::class)
        ->callAction(TestAction::make('adjustPoints')->table($this->member), ['points' => 15, 'reason' => 'Promo pembukaan'])
        ->assertHasNoActionErrors()
        ->mountAction(TestAction::make('points')->table($this->member))
        ->assertMountedActionModalSee('Promo pembukaan');

    expect($this->member->refresh()->points_balance)->toBe(15);

    $this->actingAs(User::factory()->supervisor()->forOutlet($this->pos->outlet)->create());
    expect(auth()->user()->can('member.points_adjust'))->toBeFalse();
});

it('isolasi tenant: kedaluwarsa & ledger per tenant', function () {
    pointSale($this);
    $foreign = posSetup();
    $count = TenantContext::run($foreign->tenantId, fn () => MemberPointEntry::query()->count());

    expect($count)->toBe(0);
});
