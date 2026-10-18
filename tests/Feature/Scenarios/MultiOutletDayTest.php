<?php

declare(strict_types=1);

use App\Actions\Payment\SetPaymentMethodAtOutlet;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
 * Skenario fase 3: satu hari kerja owner dengan dua outlet (ADR 0010, ADR 0011, SPEC Q36–Q45).
 *
 * Outlet A = posSetup() JKT01: pajak 11% eksklusif, service 5%, pembulatan 100 (kasir Budi, perangkat kasir-1).
 * Outlet B = BDG01: pajak 11% eksklusif, tanpa service, pembulatan 100 (kasir Rina hanya di B, perangkat kasir-bdg).
 * Menu B: Es Kopi Susu tidak dijual, QRIS nonaktif. Rabu 14 Okt 2026 10:00 WIB.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 03:00:00');
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->outletB = Outlet::factory()->create([
            'code' => 'BDG01', 'name' => 'Outlet Bandung', 'tax_rate' => '11.00', 'service_charge_rate' => '0.00',
            'tax_inclusive' => false, 'rounding' => 100,
        ]);
        $this->deviceB = Device::factory()->forOutlet($this->outletB)->create(['device_uid' => 'kasir-bdg']);
        $this->cashierB = User::factory()->cashier()->forOutlet($this->outletB)->create(['name' => 'Rina']);
        $this->shiftB = Shift::factory()->forDevice($this->deviceB, $this->cashierB)->create(['opening_cash' => '150000.00']);
        $this->owner = User::factory()->owner()->create(['name' => 'Owner']);

        // Menu per outlet (ADR 0011): kopi tidak dijual di B, QRIS nonaktif di B
        ProductStock::for($this->pos->coffee->id, $this->outletB->id)->update(['is_listed' => false]);
        app(SetPaymentMethodAtOutlet::class)->handle($this->pos->methods['qris'], $this->outletB, false);
    });

    $this->tokenB = userToken($this->cashierB, $this->deviceB);
});

afterEach(fn () => Carbon::setTestNow());

/** Checkout lewat API dengan token kasir tertentu. */
function modtCheckout(object $test, string $token, array $items, array $payments, ?string $id = null): TestResponse
{
    freshAuth();

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => $id ?? uuid(), 'order_type' => 'takeaway', 'items' => $items, 'payments' => $payments,
    ], apiHeaders());
}

/** Pembayaran tunai satu baris. */
function modtCash(object $test, int $amount): array
{
    return [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => $amount, 'tendered' => $amount]];
}

/** GET /reports/summary hari ini sebagai owner (tanpa perangkat) untuk outlet tertentu / "all". */
function modtSummary(object $test, string $outlet): array
{
    freshAuth();

    return $test->withToken(userToken($test->owner))
        ->getJson("/api/v1/reports/summary?from=2026-10-14&to=2026-10-14&outlet_id={$outlet}", apiHeaders())
        ->assertOk()
        ->json('data');
}

/** Katalog perangkat: id produk & id metode bayar. */
function modtCatalog(object $test, string $token): array
{
    freshAuth();
    $data = $test->withToken($token)->getJson('/api/v1/catalog', apiHeaders())->assertOk()->json('data');

    return [array_column($data['products'], 'id'), array_column($data['payment_methods'], 'id')];
}

it('hari kerja dua outlet: menu per outlet, kasir terikat outlet, laporan semua outlet = A + B', function () {
    $croissant = $this->pos->croissant->id;
    $coffee = $this->pos->coffee->id;
    $qris = $this->pos->methods['qris']->id;
    $deviceA = $this->pos->device;

    // --- Langkah 1: katalog per outlet (ADR 0011)
    [$productsA, $methodsA] = modtCatalog($this, $this->pos->token);
    [$productsB, $methodsB] = modtCatalog($this, $this->tokenB);
    expect($productsA)->toContain($coffee, $croissant)->and($methodsA)->toContain($qris)
        ->and($productsB)->toContain($croissant)->not->toContain($coffee)
        ->and($methodsB)->not->toContain($qris)->toContain($this->pos->methods['cash']->id);

    // --- Langkah 2: Rina (hanya outlet B) mencoba bertransaksi di perangkat A
    // a. Layar PIN perangkat A tidak menampilkan Rina, dan PIN-nya ditolak seperti PIN salah
    freshAuth();
    $names = $this->withToken(deviceToken($deviceA))->getJson('/api/v1/devices/kasir-1/cashiers', apiHeaders())
        ->assertOk()->json('data.*.name');
    expect($names)->toContain('Budi')->not->toContain('Rina');
    freshAuth();
    assertApiError(
        $this->withToken(deviceToken($deviceA))->postJson('/api/v1/auth/pin-login', ['user_id' => $this->cashierB->id, 'pin' => '123456'], apiHeaders()),
        'INVALID_PIN',
        401,
    );
    // b. Token Rina yang terikat perangkat A ditolak middleware outlet (403 FORBIDDEN), tidak ada order dibuat
    $rinaOnA = userToken($this->cashierB, $deviceA);
    freshAuth();
    assertApiError($this->withToken($rinaOnA)->getJson('/api/v1/catalog', apiHeaders()), 'FORBIDDEN', 403);
    $rejectedId = uuid();
    assertApiError(modtCheckout($this, $rinaOnA, [['product_id' => $croissant, 'qty' => 1]], modtCash($this, 50000), $rejectedId), 'FORBIDDEN', 403);
    expect(Order::allTenants()->whereKey($rejectedId)->exists())->toBeFalse();
    // userToken() memakai nama token yang sama → token lama Rina diganti (IssueUserToken); Rina login ulang di B
    $this->tokenB = userToken($this->cashierB, $this->deviceB);

    // --- Langkah 3: menu outlet B ditegakkan saat checkout
    assertApiError(modtCheckout($this, $this->tokenB, [['product_id' => $coffee, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]]], modtCash($this, 50000)), 'VALIDATION_ERROR', 422);
    assertApiError(modtCheckout($this, $this->tokenB, [['product_id' => $croissant, 'qty' => 1]], [
        ['id' => uuid(), 'payment_method_id' => $qris, 'amount' => '26600', 'reference' => 'QR-B1'],
    ]), 'VALIDATION_ERROR', 422);

    // --- Langkah 4: penjualan outlet A (service 5%, pajak 11%)
    // A1: 2 × 22.000 = 44.000; service 2.200; pajak 11% × 46.200 = 5.082; 51.282 → 51.300 (pembulatan +18)
    modtCheckout($this, $this->pos->token, [['product_id' => $coffee, 'qty' => 2, 'option_ids' => [$this->pos->regular->id]]], modtCash($this, 60000))
        ->assertCreated()->assertJsonPath('data.grand_total', '51300.00');
    // A2: 1 × 24.000; service 1.200; pajak 11% × 25.200 = 2.772; 27.972 → 28.000 (pembulatan +28), QRIS
    modtCheckout($this, $this->pos->token, [['product_id' => $croissant, 'qty' => 1]], [
        ['id' => uuid(), 'payment_method_id' => $qris, 'amount' => '28000', 'reference' => 'QR-A2'],
    ])->assertCreated()->assertJsonPath('data.grand_total', '28000.00');

    // --- Langkah 5: penjualan outlet B (tanpa service, pajak 11%)
    // B1: 3 × 24.000 = 72.000; pajak 7.920; 79.920 → 79.900 (pembulatan −20)
    modtCheckout($this, $this->tokenB, [['product_id' => $croissant, 'qty' => 3]], modtCash($this, 100000))
        ->assertCreated()->assertJsonPath('data.grand_total', '79900.00');
    // B2: 1 × 24.000; pajak 2.640; 26.640 → 26.600 (pembulatan −40)
    modtCheckout($this, $this->tokenB, [['product_id' => $croissant, 'qty' => 1]], modtCash($this, 30000))
        ->assertCreated()->assertJsonPath('data.grand_total', '26600.00');

    // Stok croissant per outlet (ADR 0010): A 10 − 1 = 9; B 0 − 4 = −4 (stok boleh minus)
    expect(stockOf($this->pos->croissant, $this->pos->outlet->id)->stock_qty)->toBe(9)
        ->and(stockOf($this->pos->croissant, $this->outletB->id)->stock_qty)->toBe(-4);

    // --- Langkah 6: laporan ringkasan per outlet & semua outlet
    $a = modtSummary($this, $this->pos->outlet->id);
    $b = modtSummary($this, $this->outletB->id);
    $all = modtSummary($this, 'all');

    // A: 51.300 + 28.000 = 79.300; subtotal 68.000; service 3.400; pajak 5.082 + 2.772 = 7.854; pembulatan +46;
    //    net = 79.300 − 7.854 − 3.400 − 46 = 68.000; item 2 + 1 = 3
    expect($a)->toMatchArray([
        'order_count' => 2, 'revenue' => '79300.00', 'subtotal_total' => '68000.00', 'service_total' => '3400.00',
        'tax_total' => '7854.00', 'rounding_total' => '46.00', 'net_sales' => '68000.00', 'items_sold' => 3, 'average' => '39650.00',
    ]);
    // B: 79.900 + 26.600 = 106.500; subtotal 96.000; service 0; pajak 7.920 + 2.640 = 10.560; pembulatan −60;
    //    net = 106.500 − 10.560 − 0 + 60 = 96.000; item 3 + 1 = 4
    expect($b)->toMatchArray([
        'order_count' => 2, 'revenue' => '106500.00', 'subtotal_total' => '96000.00', 'service_total' => '0.00',
        'tax_total' => '10560.00', 'rounding_total' => '-60.00', 'net_sales' => '96000.00', 'items_sold' => 4, 'average' => '53250.00',
    ]);
    // Semua: 79.300 + 106.500 = 185.800; subtotal 164.000; service 3.400; pajak 18.414; pembulatan 46 − 60 = −14;
    //        net 164.000; item 7; rata-rata 185.800 ÷ 4 = 46.450
    expect($all)->toMatchArray([
        'order_count' => 4, 'revenue' => '185800.00', 'subtotal_total' => '164000.00', 'service_total' => '3400.00',
        'tax_total' => '18414.00', 'rounding_total' => '-14.00', 'net_sales' => '164000.00', 'items_sold' => 7, 'average' => '46450.00',
    ]);

    // all = A + B tepat untuk setiap angka penjumlahan
    foreach (['revenue', 'subtotal_total', 'discount_total', 'service_total', 'tax_total', 'rounding_total', 'net_sales', 'void_total', 'refund_total', 'net_revenue'] as $key) {
        expect(bcadd((string) $a[$key], (string) $b[$key], 2))->toBe($all[$key], "all.{$key} ≠ A + B");
    }
    foreach (['order_count', 'items_sold', 'void_count', 'refund_count'] as $key) {
        expect($a[$key] + $b[$key])->toBe($all[$key], "all.{$key} ≠ A + B");
    }
});

it('request checkout ganda di outlet B tidak menggandakan angka laporan semua outlet', function () {
    $id = uuid();
    $payments = modtCash($this, 30000);
    $items = [['product_id' => $this->pos->croissant->id, 'qty' => 1]];

    modtCheckout($this, $this->tokenB, $items, $payments, $id)->assertCreated();
    modtCheckout($this, $this->tokenB, $items, $payments, $id)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    // Satu order 26.600 (24.000 + pajak 2.640 = 26.640 → 26.600); stok B −1, bukan −2
    expect(modtSummary($this, 'all'))->toMatchArray(['order_count' => 1, 'revenue' => '26600.00'])
        ->and(stockOf($this->pos->croissant, $this->outletB->id)->stock_qty)->toBe(-1);
});

it('owner di perangkat outlet A tanpa outlet_id hanya melihat angka outlet A', function () {
    modtCheckout($this, $this->pos->token, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], modtCash($this, 30000))->assertCreated();
    modtCheckout($this, $this->tokenB, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], modtCash($this, 30000))->assertCreated();

    freshAuth();
    // Hanya order A: 24.000 + service 1.200 + pajak 2.772 = 27.972 → 28.000
    $this->withToken(userToken($this->owner, $this->pos->device))
        ->getJson('/api/v1/reports/summary?from=2026-10-14&to=2026-10-14', apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.order_count', 1)
        ->assertJsonPath('data.revenue', '28000.00');
});

it('laporan bisnis lain tidak ikut terhitung di semua outlet', function () {
    modtCheckout($this, $this->tokenB, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], modtCash($this, 30000))->assertCreated();

    $other = posSetup();
    $otherOwner = TenantContext::run($other->tenantId, fn () => User::factory()->owner()->create());
    freshAuth();
    modtCheckout($this, $other->token, [['product_id' => $other->croissant->id, 'qty' => 1]], [
        ['id' => uuid(), 'payment_method_id' => $other->methods['cash']->id, 'amount' => 30000, 'tendered' => 30000],
    ])->assertCreated();

    expect(modtSummary($this, 'all')['order_count'])->toBe(1);
    freshAuth();
    // Outlet B milik bisnis pertama tidak bisa dipilih owner bisnis lain
    assertApiError(
        $this->withToken(userToken($otherOwner))->getJson("/api/v1/reports/summary?outlet_id={$this->outletB->id}", apiHeaders()),
        'NOT_FOUND',
        404,
    );
});
