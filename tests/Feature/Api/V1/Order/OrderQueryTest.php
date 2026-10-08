<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 05:00:00'); // 12.00 WIB
    $this->pos = posSetup();

    // Order kasir Budi (shift sendiri)
    $this->own = checkoutVia($this, $this->pos->token);

    // Order kasir lain di shift lain (device lain)
    $this->otherCashier = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
    $device2 = Device::factory()->forOutlet($this->pos->outlet)->create();
    TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($device2, $this->otherCashier)->create());
    $this->otherToken = userToken($this->otherCashier, $device2);
    $this->others = checkoutVia($this, $this->otherToken);

    // Open bill kasir lain
    $this->openBillId = uuid();
    freshAuth();
    $this->withToken($this->otherToken)->putJson("/api/v1/orders/{$this->openBillId}", [
        'table_label' => 'Meja 9',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], apiHeaders())->assertCreated();
});

function checkoutVia(object $test, string $token, ?stdClass $pos = null): string
{
    freshAuth();
    $pos ??= $test->pos;

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 30000]],
    ], apiHeaders())->assertCreated()->json('data.id');
}

function listIds(object $test, string $token, string $query = ''): array
{
    freshAuth();

    return $test->withToken($token)->getJson('/api/v1/orders'.$query, apiHeaders())->assertOk()->json('data.*.id');
}

it('kasir melihat order di shift sendiri + semua open bill, bukan order kasir lain', function () {
    expect(listIds($this, $this->pos->token))->toEqualCanonicalizing([$this->own, $this->openBillId]);
});

it('owner melihat semua; filter status & pencarian meja', function () {
    $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $token = userToken($owner);

    expect(listIds($this, $token))->toHaveCount(3)
        ->and(listIds($this, $token, '?status=open'))->toBe([$this->openBillId])
        ->and(listIds($this, $token, '?search=Meja%209'))->toBe([$this->openBillId])
        ->and(listIds($this, $token, '?date=2026-10-07'))->toBe([]);
});

it('supervisor hanya melihat transaksi hari ini', function () {
    $token = userToken($this->pos->supervisor);
    expect(listIds($this, $token))->toHaveCount(3);

    Carbon::setTestNow('2026-10-09 05:00:00');
    expect(listIds($this, $token))->toBe([]);
});

it('validasi filter', function () {
    assertApiError($this->withToken($this->pos->token)->getJson('/api/v1/orders?date=08-10-2026', apiHeaders()), 'VALIDATION_ERROR', 422);
});

it('detail: kasir tidak bisa membuka order kasir lain', function () {
    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$this->own}", apiHeaders())->assertOk()->assertJsonPath('data.id', $this->own);

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->getJson("/api/v1/orders/{$this->others}", apiHeaders()), 'FORBIDDEN', 403);
});

it('isolasi: order tenant lain → 404 di daftar, detail, dan struk', function () {
    $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $token = userToken($owner);

    $other = posSetup();
    $foreign = checkoutVia($this, $other->token, $other);

    expect(listIds($this, $token))->not->toContain($foreign);
    freshAuth();
    assertApiError($this->withToken($token)->getJson("/api/v1/orders/{$foreign}", apiHeaders()), 'NOT_FOUND', 404);
    freshAuth();
    assertApiError($this->withToken($token)->getJson("/api/v1/orders/{$foreign}/receipt", apiHeaders()), 'NOT_FOUND', 404);
});

it('struk berisi snapshot, tarif saat transaksi, dan waktu lokal outlet', function () {
    $this->pos->outlet->update(['tax_rate' => '0.00', 'receipt_footer' => 'Terima kasih!']);

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$this->own}/receipt", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.outlet.footer', 'Terima kasih!')
        ->assertJsonPath('data.cashier_name', 'Budi')
        ->assertJsonPath('data.created_at_local', '08/10/2026 12.00')
        ->assertJsonPath('data.lines.0.name', 'Croissant')
        ->assertJsonPath('data.totals.tax_rate', '11.00')
        ->assertJsonPath('data.totals.grand_total', '28000.00')
        ->assertJsonPath('data.payments.0.method', 'Tunai')
        ->assertJsonPath('data.is_void', false);
});
