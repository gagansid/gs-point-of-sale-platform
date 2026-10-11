<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\Category;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * API modul laporan (SPEC Q49–Q51): trend, categories, options, employees, shifts, shifts/{id},
 * heatmap, stock; batas 31 hari supervisor; rekap shift sendiri untuk kasir.
 */
function moduleSale(stdClass $pos, string $utc, array $items, ?User $cashier = null): Order
{
    Carbon::setTestNow($utc);

    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($cashier ?? $pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 500000]],
    ]))['order']);
}

function moduleFetch(object $test, string $path, User $user): TestResponse
{
    freshAuth();

    return $test->withToken(userToken($user))->getJson("/api/v1/reports/{$path}", apiHeaders());
}

beforeEach(function () {
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    TenantContext::run($this->pos->tenantId, function () {
        $category = Category::factory()->create(['name' => 'Kopi']);
        $this->pos->coffee->update(['category_id' => $category->id]);
        $this->pos->shift->forceFill(['opened_at' => '2026-10-08 00:00:00'])->save();
    });

    moduleSale($this->pos, '2026-10-08 03:00:00', [['product_id' => $this->pos->coffee->id, 'qty' => 2, 'option_ids' => [$this->pos->large->id]]]);
    moduleSale($this->pos, '2026-10-09 03:00:00', [['product_id' => $this->pos->croissant->id, 'qty' => 1]]);

    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::forget();
});

afterEach(fn () => Carbon::setTestNow());

it('tren per granularitas + pembanding', function () {
    moduleFetch($this, 'trend?from=2026-09-01&to=2026-10-10&granularity=week', $this->owner)
        ->assertOk()
        ->assertJsonPath('data.period.granularity', 'week')
        ->assertJsonPath('data.trend.0.key', '2026-08-31')
        ->assertJsonPath('data.comparison.to', '2026-08-31');

    // Granularitas tidak cocok dengan rentang → bawaan
    moduleFetch($this, 'trend?from=2026-10-10&to=2026-10-10&granularity=month', $this->owner)
        ->assertJsonPath('data.period.granularity', 'hour')
        ->assertJsonCount(24, 'data.trend');
});

it('kategori, opsi, karyawan, shift, heatmap, stok', function () {
    $q = 'from=2026-10-01&to=2026-10-10';

    moduleFetch($this, "categories?{$q}", $this->owner)->assertOk()
        ->assertJsonPath('data.categories.0.category_name', 'Kopi')
        ->assertJsonPath('data.categories.0.qty', 2);
    moduleFetch($this, "options?{$q}", $this->owner)->assertJsonPath('data.options.0.option_name', 'Large')->assertJsonPath('data.options.0.revenue', '10000.00');
    moduleFetch($this, "employees?{$q}", $this->owner)->assertJsonPath('data.employees.0.name', 'Budi')->assertJsonPath('data.employees.0.order_count', 2);
    moduleFetch($this, "shifts?{$q}", $this->owner)->assertJsonPath('data.shifts.0.cashier', 'Budi')->assertJsonPath('data.shifts.0.order_count', 2);
    moduleFetch($this, "heatmap?{$q}", $this->owner)->assertJsonPath('data.heatmap.4.10', 1); // Kamis 10.00 WIB
    moduleFetch($this, "stock?{$q}", $this->owner)->assertJsonPath('data.stock.0.product_name', 'Croissant')->assertJsonPath('data.stock.0.sold', 1);
});

it('filter kategori & produk di API; ID tidak valid → 422', function () {
    $q = 'from=2026-10-01&to=2026-10-10';

    moduleFetch($this, "summary?{$q}&product_ids[]=".$this->pos->croissant->id, $this->owner)->assertJsonPath('data.order_count', 1);
    assertApiError(moduleFetch($this, "summary?{$q}&category_ids[]=kopi", $this->owner), 'VALIDATION_ERROR', 422);
    assertApiError(moduleFetch($this, "trend?{$q}&granularity=year", $this->owner), 'VALIDATION_ERROR', 422);
});

it('supervisor: tab operasional ≤ 31 hari; tab lain & rentang lama ditolak (Q49)', function () {
    $supervisor = $this->pos->supervisor;

    foreach (['summary', 'products', 'payment-methods', 'employees', 'shifts', 'audit', 'trend'] as $path) {
        moduleFetch($this, "{$path}?from=2026-09-10&to=2026-10-10", $supervisor)->assertOk();
    }

    // 2026-09-09 = 32 hari ke belakang dari 10 Okt
    assertApiError(moduleFetch($this, 'summary?from=2026-09-09&to=2026-10-10', $supervisor), 'VALIDATION_ERROR', 422);

    foreach (['categories', 'options', 'heatmap', 'stock', 'dashboard', 'hourly'] as $path) {
        assertApiError(moduleFetch($this, $path, $supervisor), 'FORBIDDEN', 403);
    }
});

it('kasir: hanya rekap shift sendiri (X-report), shift orang lain 403', function () {
    $shift = $this->pos->shift;

    moduleFetch($this, "shifts/{$shift->id}", $this->pos->cashier)
        ->assertOk()
        ->assertJsonPath('data.type', 'x_report')
        ->assertJsonPath('data.shift.cashier', 'Budi')
        ->assertJsonPath('data.sales.order_count', 2)
        ->assertJsonPath('data.cash.actual_cash', null)
        ->assertJsonPath('data.products.0.product_name', 'Es Kopi Susu');

    assertApiError(moduleFetch($this, 'shifts', $this->pos->cashier), 'FORBIDDEN', 403);

    $otherCashier = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
    assertApiError(moduleFetch($this, "shifts/{$shift->id}", $otherCashier), 'FORBIDDEN', 403);
});

it('Z-report memakai angka saat tutup shift; supervisor boleh shift ≤ 31 hari', function () {
    TenantContext::run($this->pos->tenantId, fn () => $this->pos->shift->forceFill([
        'status' => 'closed', 'expected_cash' => '300000.00', 'actual_cash' => '290000.00',
        'difference' => '-10000.00', 'closed_by' => $this->pos->cashier->id, 'closed_at' => '2026-10-09 10:00:00',
    ])->save());

    moduleFetch($this, "shifts/{$this->pos->shift->id}", $this->pos->supervisor)
        ->assertOk()
        ->assertJsonPath('data.type', 'z_report')
        ->assertJsonPath('data.cash.difference', '-10000.00');

    TenantContext::run($this->pos->tenantId, fn () => $this->pos->shift->forceFill(['opened_at' => '2026-08-01 00:00:00'])->save());
    assertApiError(moduleFetch($this, "shifts/{$this->pos->shift->id}", $this->pos->supervisor), 'FORBIDDEN', 403);
    moduleFetch($this, "shifts/{$this->pos->shift->id}", $this->owner)->assertOk();
});

it('isolasi: shift tenant lain → 404, angka tidak bocor', function () {
    $other = posSetup();
    moduleSale($other, '2026-10-08 03:00:00', [['product_id' => $other->croissant->id, 'qty' => 3]]);
    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::forget();

    assertApiError(moduleFetch($this, "shifts/{$other->shift->id}", $this->owner), 'NOT_FOUND', 404);
    moduleFetch($this, 'categories?from=2026-10-01&to=2026-10-10', $this->owner)->assertJsonCount(2, 'data.categories')->assertJsonPath('data.categories.1.qty', 1);
    moduleFetch($this, 'employees?from=2026-10-01&to=2026-10-10', $this->owner)->assertJsonCount(1, 'data.employees');
});

it('request ganda menghasilkan angka yang sama (hanya baca)', function () {
    $first = moduleFetch($this, 'shifts?from=2026-10-01&to=2026-10-10', $this->owner)->json('data');

    expect(moduleFetch($this, 'shifts?from=2026-10-01&to=2026-10-10', $this->owner)->json('data'))->toBe($first)
        ->and(Shift::allTenants()->count())->toBe(1);
});
