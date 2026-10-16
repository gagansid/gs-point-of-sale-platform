<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\Member;
use App\Models\Order;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Isolasi tenant & izin laporan lewat HTTP (fase 2). Tenant A: 1 Croissant (28.000).
 * Tenant B: produk, opsi, dan member bernama unik + nominal unik; tidak boleh muncul di laporan tenant A.
 */
function isoSale(stdClass $pos, array $items, ?string $memberId = null): Order
{
    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $memberId, 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 1000000]],
    ]))['order']);
}

function isoFetch(object $test, string $path, User $user): TestResponse
{
    freshAuth();

    return $test->withToken(userToken($user))->getJson("/api/v1/reports/{$path}", apiHeaders());
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 03:00:00');

    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    isoSale($this->pos, [['product_id' => $this->pos->croissant->id, 'qty' => 1]]);

    // Tenant B dengan nama & nominal yang hanya ada di tenant B
    $this->other = posSetup();
    $this->foreignMember = TenantContext::run($this->other->tenantId, function (): Member {
        $this->other->croissant->update(['name' => 'Roti Rahasia Tenant B']);
        $this->other->coffee->update(['name' => 'Kopi Rahasia Tenant B']);
        $this->other->large->update(['name' => 'Ekstra Rahasia Tenant B']);
        app(UpdateMemberProgram::class)->handle(true, [$this->other->outlet->id]);

        return Member::factory()->create(['name' => 'Member Rahasia Tenant B']);
    });
    Carbon::setTestNow('2026-10-08 13:00:00'); // 20.00 WIB: jam yang tidak dipakai tenant A
    $this->foreignOrder = isoSale($this->other, [
        ['product_id' => $this->other->croissant->id, 'qty' => 7],
        ['product_id' => $this->other->coffee->id, 'qty' => 3, 'option_ids' => [$this->other->large->id]],
    ], $this->foreignMember->id);

    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::forget();
});

afterEach(fn () => Carbon::setTestNow());

describe('isolasi tenant laporan', function () {
    it('tidak memuat nama, nominal, member, atau shift tenant lain', function (string $path) {
        $response = isoFetch($this, "{$path}?from=2026-10-01&to=2026-10-10", $this->owner)->assertOk();

        foreach ([
            'Roti Rahasia Tenant B', 'Kopi Rahasia Tenant B', 'Ekstra Rahasia Tenant B', 'Member Rahasia Tenant B',
            $this->foreignMember->id, $this->other->shift->id, $this->other->croissant->id, $this->other->coffee->id,
            $this->foreignOrder->grand_total, $this->foreignOrder->subtotal, '168000.00',
        ] as $secret) {
            $response->assertDontSee($secret, false);
        }
    })->with(['products', 'payment-methods', 'hourly', 'trend', 'options', 'heatmap', 'stock', 'channels', 'members', 'shifts']);

    it('kontrol: owner tenant B melihat datanya sendiri (penanda yang dicari memang muncul)', function () {
        $ownerB = User::factory()->owner()->create(['tenant_id' => $this->other->tenantId]);
        $q = 'from=2026-10-01&to=2026-10-10';

        isoFetch($this, "products?{$q}", $ownerB)->assertSee('Roti Rahasia Tenant B')->assertSee('168000.00');
        isoFetch($this, "options?{$q}", $ownerB)->assertSee('Ekstra Rahasia Tenant B');
        isoFetch($this, "members?{$q}", $ownerB)->assertSee('Member Rahasia Tenant B');
        isoFetch($this, "shifts?{$q}", $ownerB)->assertSee($this->other->shift->id);
        isoFetch($this, "payment-methods?{$q}", $ownerB)->assertSee($this->foreignOrder->grand_total);
    });

    it('angka per jam, heatmap, dan kanal hanya menghitung transaksi tenant sendiri', function () {
        $q = 'from=2026-10-01&to=2026-10-10';

        $hours = isoFetch($this, "hourly?{$q}", $this->owner)->json('data.hours');
        expect(collect($hours)->sum('order_count'))->toBe(1)
            ->and($hours[20]['order_count'])->toBe(0);

        $heatmap = isoFetch($this, "heatmap?{$q}", $this->owner)->json('data.heatmap');
        expect(collect($heatmap)->flatten()->sum())->toBe(1);

        isoFetch($this, "channels?{$q}", $this->owner)->assertJsonPath('data.channels.0.order_count', 1);
        isoFetch($this, "members?{$q}", $this->owner)
            ->assertJsonPath('data.summary.member_orders', 0)
            ->assertJsonCount(0, 'data.top_members');
        isoFetch($this, "shifts?{$q}", $this->owner)
            ->assertJsonCount(1, 'data.shifts')
            ->assertJsonPath('data.shifts.0.shift_id', $this->pos->shift->id);
    });
});

describe('GET /reports/shifts/{shift}/pdf', function () {
    it('menolak PDF rekap shift tenant lain dengan 404', function () {
        assertApiError(isoFetch($this, "shifts/{$this->other->shift->id}/pdf", $this->owner), 'NOT_FOUND', 404);
    });
});

describe('izin laporan', function () {
    it('menolak kasir (tanpa report.view / report.view_shift) dengan 403', function (string $path) {
        assertApiError(isoFetch($this, $path, $this->pos->cashier), 'FORBIDDEN', 403);
    })->with(['employees', 'payment-methods', 'products', 'trend']);
});
