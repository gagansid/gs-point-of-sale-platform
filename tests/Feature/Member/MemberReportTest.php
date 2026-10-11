<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\SaveMemberTiers;
use App\Actions\Member\SaveStampProgram;
use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\ReportTab;
use App\Exports\ReportSectionBuilder;
use App\Filament\Dashboard\Widgets\Reports\MemberStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\MemberTopTableWidget;
use App\Models\Member;
use App\Models\User;
use App\Services\Member\StampCards;
use App\Services\Report\DailySummaryBuilder;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Laporan member & ringkasan harian (ADR 0013, M7). 8 Okt 2026 WIB; Croissant 24.000 → 28.000.
 * Rina: 2 transaksi (1 dengan voucher 5.000), Budi: 1 transaksi; 1 transaksi non-member.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    Carbon::setTestNow('2026-10-08 03:00:00');
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(SaveMemberTiers::class)->handle([['name' => 'Gold', 'min_spend_12m' => '50000', 'point_multiplier' => '1', 'discount_percent' => '0']]);
    app(SaveVoucher::class)->handle(null, ['code' => 'HEMAT5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);
    app(SaveStampProgram::class)->handle(null, ['name' => 'Roti', 'stamps_required' => 2, 'product_ids' => [$this->pos->croissant->id]]);
    $this->rina = Member::factory()->create(['name' => 'Rina']);
    $this->budi = Member::factory()->create(['name' => 'Budi', 'joined_outlet_id' => $this->pos->outlet->id]);
    $this->owner = User::factory()->owner()->create();
    app(AdjustMemberPoints::class)->handle($this->budi, 30, 'Saldo awal', $this->owner);

    $sale = fn (?Member $member, array $extra = []) => app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $member?->id,
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
        ...$extra,
    ]));
    $sale($this->rina);
    $sale($this->rina, ['voucher_code' => 'HEMAT5']);
    $sale($this->budi);
    $sale(null);
    // Rina: 2 stempel → gratis Croissant di transaksi ke-3
    $sale($this->rina, ['stamp_program_ids' => [app(StampCards::class)->activePrograms()->first()->id], 'items' => [
        ['product_id' => $this->pos->croissant->id, 'qty' => 2],
    ]]);
    CurrentOutlet::set(null, $this->pos->outlet->id);
});

afterEach(fn () => Carbon::setTestNow());

function memberReport(): array
{
    return app(ReportService::class)->members(CarbonImmutable::parse('2026-10-07 17:00'), CarbonImmutable::parse('2026-10-08 16:59:59'));
}

it('ringkasan member vs non-member, member aktif/berulang/baru, voucher, stempel, utang poin', function () {
    $report = memberReport();
    $s = $report['summary'];

    // 28.000 (Rina) + 22.100 (voucher) + 28.000 (Budi) + 28.000 (Rina, 1 gratis dari 2) = 106.100; non-member 28.000
    expect($s['member_orders'])->toBe(4)
        ->and($s['member_revenue'])->toBe('106100.00')
        ->and($s['guest_orders'])->toBe(1)
        ->and($s['guest_revenue'])->toBe('28000.00')
        ->and($s['member_share'])->toBe('79.1')
        ->and($s['active_members'])->toBe(2)
        ->and($s['repeat_members'])->toBe(1)
        // Outlet terpilih: hanya member yang mendaftar di outlet ini (Budi); Rina daftar dari dashboard
        ->and($s['new_members'])->toBe(1)
        ->and($s['voucher_uses'])->toBe(1)
        ->and($s['voucher_discount'])->toBe('5000.00')
        ->and($s['stamp_free_count'])->toBe(1)
        ->and($s['stamp_free_total'])->toBe('24000.00')
        // Poin 2 + 1 + 2 + 2 = 7 dari transaksi; saldo aktif 7 + 30 (penyesuaian) = 37 × Rp100
        ->and($s['points_earned'])->toBe(7)
        ->and($s['points_liability'])->toBe(37)
        ->and($s['points_liability_value'])->toBe('3700.00');

    expect($report['top_members'][0])->toMatchArray(['member_name' => 'Rina', 'order_count' => 3, 'revenue' => '78100.00', 'tier' => 'Gold'])
        ->and($report['vouchers'])->toBe([['voucher_code' => 'HEMAT5', 'uses' => 1, 'discount' => '5000.00']])
        ->and(collect($report['tiers'])->pluck('member_count', 'tier')->all())->toBe(['Gold' => 1, 'Tanpa tier' => 1]);
});

it('isolasi tenant: data bisnis lain tidak terhitung', function () {
    $other = posSetup();
    TenantContext::set($other->tenantId);
    CurrentOutlet::set(null, $other->outlet->id);

    $s = memberReport()['summary'];
    expect($s['member_orders'])->toBe(0)->and($s['points_liability'])->toBe(0)->and($s['new_members'])->toBe(0);
});

it('API /reports/members: owner boleh; supervisor & kasir ditolak', function () {
    $owner = $this->owner;
    $query = '/api/v1/reports/members?from=2026-10-08&to=2026-10-08';

    $this->withToken(userToken($owner))->getJson($query, apiHeaders())
        ->assertOk()->assertJsonPath('data.summary.member_orders', 4)->assertJsonPath('data.top_members.0.member_name', 'Rina');

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->getJson($query, apiHeaders()), 'FORBIDDEN', 403);
    freshAuth();
    assertApiError($this->withToken(userToken($this->pos->supervisor, $this->pos->device))->getJson($query, apiHeaders()), 'FORBIDDEN', 403);
});

it('dashboard: tab Member tampil & widget render; supervisor tidak; export memuat bagian member', function () {
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    expect(ReportTab::Members->visibleFor($this->owner))->toBeTrue()
        ->and(ReportTab::Members->visibleFor($this->pos->supervisor))->toBeFalse();

    $range = ['preset' => 'custom', 'from' => '2026-10-08', 'to' => '2026-10-08'];
    Livewire::test(MemberStatsWidget::class, ['pageFilters' => $range])->assertSee('Omzet member')->assertSee('Rp3.700');
    Livewire::test(MemberTopTableWidget::class, ['pageFilters' => $range])->assertSee('Rina')->assertSee('Gold');

    $context = ReportContext::fromInput($range, $this->owner);
    $titles = array_map(fn ($s) => $s->title, app(ReportSectionBuilder::class)->build([ReportTab::Members], $context, 1000));
    expect($titles)->toBe(['Ringkasan member', 'Member teratas', 'Pemakaian voucher']);
});

it('ringkasan harian memuat baris member bila program aktif', function () {
    $summary = app(DailySummaryBuilder::class)->build($this->owner, '2026-10-08');

    expect($summary['members'])->toBe(['member_orders' => 4, 'member_revenue' => '106100.00', 'new_members' => 1, 'points_redeemed' => 0]);

    app(UpdateMemberProgram::class)->handle(false, []);
    expect(app(DailySummaryBuilder::class)->build($this->owner, '2026-10-08')['members'])->toBeNull();
});
