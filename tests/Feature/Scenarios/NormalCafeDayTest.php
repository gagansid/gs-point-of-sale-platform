<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
 * Skenario Phase 3: satu hari kerja normal kafe di outlet JKT01 (pajak 11% exclusive, service 5%,
 * pembulatan Rp100, batas diskon kasir 10%).
 *
 * Rumus (SPEC → Urutan perhitungan total): dasar B → service S = 5% × B → pajak T = 11% × (B + S)
 * → total = bulat ke Rp100 terdekat (half-up) dari B + S + T (= B × 1,1655).
 *
 * Katalog: Es Kopi Susu 22.000 (Ukuran wajib: Regular +0 / Large +5.000, tanpa stok),
 * Croissant 24.000 (stok 10), Teh Tarik 15.000 (stok 20).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 02:00:00'); // 09.00 WIB
    $this->pos = posSetup(openShift: false);
    $this->pos->tea = TenantContext::run($this->pos->tenantId, fn (): Product => Product::factory()->tracked(20)->create(['name' => 'Teh Tarik', 'price' => '15000.00']));
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
});

afterEach(fn () => Carbon::setTestNow());

/** Request API kasir (token kasir Budi di device kasir-1). */
function ncdApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Satu baris item keranjang. */
function ncdItem(Product $product, int $qty, array $optionIds = [], array $extra = []): array
{
    return ['product_id' => $product->id, 'qty' => $qty, 'option_ids' => $optionIds, ...$extra];
}

/** Satu pembayaran; tunai boleh membawa uang diterima (tendered). */
function ncdPay(stdClass $pos, string $category, int $amount, array $extra = []): array
{
    return ['id' => uuid(), 'payment_method_id' => $pos->methods[$category]->id, 'amount' => $amount, ...$extra];
}

function ncdCheckout(object $test, array $items, array $payments, array $extra = []): TestResponse
{
    return ncdApi($test, 'POST', '/checkout', ['id' => uuid(), 'order_type' => 'takeaway', 'items' => $items, 'payments' => $payments, ...$extra]);
}

/** Laporan hari ini (tanggal lokal outlet) sebagai owner. */
function ncdReport(object $test, string $path): TestResponse
{
    return ncdApi($test, 'GET', '/reports/'.$path, token: userToken($test->owner));
}

it('satu hari normal: 10 transaksi campuran, kas keluar, tutup shift selisih 0, laporan & stok cocok', function () {
    $pos = $this->pos;
    $coffee = $pos->coffee;
    $croissant = $pos->croissant;
    $tea = $pos->tea;
    $regular = [$pos->regular->id];
    $large = [$pos->large->id];

    // ---- Langkah 1: buka shift dengan kas awal 200.000 ----
    $shiftId = ncdApi($this, 'POST', '/shifts', ['opening_cash' => 200000])
        ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

    // ---- Langkah 2: transaksi checkout & open bill ----

    // O1 tunai: 1 Kopi Regular = 22.000; S 1.100; T 11% × 23.100 = 2.541; 25.641 → 25.600; kembali 50.000 − 25.600 = 24.400
    ncdCheckout($this, [ncdItem($coffee, 1, $regular)], [ncdPay($pos, 'cash', 50000, ['tendered' => 50000])])
        ->assertCreated()
        ->assertJsonPath('data.grand_total', '25600.00')
        ->assertJsonPath('data.change_total', '24400.00');

    // O2 QRIS: 2 Kopi Large = (22.000 + 5.000) × 2 = 54.000; S 2.700; T 11% × 56.700 = 6.237; 62.937 → 62.900
    ncdCheckout($this, [ncdItem($coffee, 2, $large)], [ncdPay($pos, 'qris', 62900)])
        ->assertCreated()
        ->assertJsonPath('data.items.0.options_total', '5000.00')
        ->assertJsonPath('data.items.0.line_total', '54000.00')
        ->assertJsonPath('data.grand_total', '62900.00');

    // O3 tunai: 2 Croissant = 48.000; S 2.400; T 11% × 50.400 = 5.544; 55.944 → 55.900; kembali 44.100
    ncdCheckout($this, [ncdItem($croissant, 2)], [ncdPay($pos, 'cash', 100000, ['tendered' => 100000])])
        ->assertCreated()
        ->assertJsonPath('data.grand_total', '55900.00')
        ->assertJsonPath('data.change_total', '44100.00');

    // O4 split debit + tunai: 1 Kopi Large 27.000 + 1 Croissant 24.000 = 51.000; S 2.550; T 11% × 53.550 = 5.890,50
    // 59.440,50 → 59.400; debit 30.000, tunai menutup 29.400 dari uang 50.000 → kembali 20.600
    ncdCheckout($this, [ncdItem($coffee, 1, $large), ncdItem($croissant, 1)], [
        ncdPay($pos, 'debit', 30000, ['reference' => 'EDC001']),
        ncdPay($pos, 'cash', 50000, ['tendered' => 50000]),
    ])
        ->assertCreated()
        ->assertJsonPath('data.grand_total', '59400.00')
        ->assertJsonPath('data.payments.1.amount', '29400.00')
        ->assertJsonPath('data.change_total', '20600.00');

    // O5 diskon item (QRIS): 2 Croissant 48.000 − diskon item 4.800 = 43.200 + 1 Kopi Regular 22.000 = subtotal 65.200
    // diskon 4.800 / kotor 70.000 = 6,86% ≤ 10% → tanpa approval; S 3.260; T 11% × 68.460 = 7.530,60; 75.990,60 → 76.000
    ncdCheckout($this, [ncdItem($croissant, 2, extra: ['discount' => 4800]), ncdItem($coffee, 1, $regular)], [ncdPay($pos, 'qris', 76000)])
        ->assertCreated()
        ->assertJsonPath('data.subtotal', '65200.00')
        ->assertJsonPath('data.discount_total', '4800.00')
        ->assertJsonPath('data.approved_by', null)
        ->assertJsonPath('data.grand_total', '76000.00');

    // O6 diskon order 10% (tunai): 1 Teh 15.000 + 1 Kopi Regular 22.000 = 37.000; diskon 3.700 → dasar 33.300
    // S 1.665; T 11% × 34.965 = 3.846,15; 38.811,15 → 38.800; kembali 40.000 − 38.800 = 1.200
    ncdCheckout($this, [ncdItem($tea, 1), ncdItem($coffee, 1, $regular)], [ncdPay($pos, 'cash', 40000, ['tendered' => 40000])], [
        'discount' => ['type' => 'percent', 'value' => 10],
    ])
        ->assertCreated()
        ->assertJsonPath('data.order_discount', '3700.00')
        ->assertJsonPath('data.approved_by', null)
        ->assertJsonPath('data.grand_total', '38800.00')
        ->assertJsonPath('data.change_total', '1200.00');

    // O7 open bill Meja 3: awal 2 Kopi Regular + 1 Croissant = 68.000, lalu tamu tambah 1 Teh → 83.000
    // S 4.150; T 11% × 87.150 = 9.586,50; 96.736,50 → 96.700. Bayar QRIS 50.000 + tunai 46.700 (uang 50.000, kembali 3.300)
    $bill7 = uuid();
    ncdApi($this, 'PUT', "/orders/{$bill7}", ['order_type' => 'dine_in', 'table_label' => 'Meja 3', 'items' => [ncdItem($coffee, 2, $regular), ncdItem($croissant, 1)]])
        ->assertCreated()->assertJsonPath('data.grand_total', '79300.00'); // 68.000 × 1,1655 = 79.254 → 79.300
    ncdApi($this, 'PUT', "/orders/{$bill7}", ['order_type' => 'dine_in', 'table_label' => 'Meja 3', 'items' => [ncdItem($coffee, 2, $regular), ncdItem($croissant, 1), ncdItem($tea, 1)]])
        ->assertOk()->assertJsonPath('data.grand_total', '96700.00');
    // Open bill belum lunas: stok belum dipotong
    expect(stockOf($croissant)->stock_qty)->toBe(10 - 2 - 1 - 2); // O3 2, O4 1, O5 2
    ncdApi($this, 'POST', "/orders/{$bill7}/payments", ['payments' => [ncdPay($pos, 'qris', 50000)]])
        ->assertCreated()->assertJsonPath('data.status', 'open');
    ncdApi($this, 'POST', "/orders/{$bill7}/payments", ['payments' => [ncdPay($pos, 'cash', 50000, ['tendered' => 50000])]])
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.paid_total', '96700.00')
        ->assertJsonPath('data.change_total', '3300.00');

    // O8 open bill Meja 7 (tunai): 3 Teh = 45.000; S 2.250; T 11% × 47.250 = 5.197,50; 52.447,50 → 52.400; uang 100.000 → kembali 47.600
    $bill8 = uuid();
    ncdApi($this, 'PUT', "/orders/{$bill8}", ['order_type' => 'dine_in', 'table_label' => 'Meja 7', 'items' => [ncdItem($tea, 3)]])
        ->assertCreated()->assertJsonPath('data.grand_total', '52400.00');
    ncdApi($this, 'POST', "/orders/{$bill8}/payments", ['payments' => [ncdPay($pos, 'cash', 100000, ['tendered' => 100000])]])
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.change_total', '47600.00');

    // O9 kartu kredit: 1 Croissant + 1 Teh = 39.000; S 1.950; T 11% × 40.950 = 4.504,50; 45.454,50 → 45.500
    ncdCheckout($this, [ncdItem($croissant, 1), ncdItem($tea, 1)], [ncdPay($pos, 'credit', 45500, ['reference' => 'CC0909'])])
        ->assertCreated()->assertJsonPath('data.grand_total', '45500.00');

    // O10 tunai uang pas: 1 Kopi Large 27.000; S 1.350; T 11% × 28.350 = 3.118,50; 31.468,50 → 31.500
    ncdCheckout($this, [ncdItem($coffee, 1, $large)], [ncdPay($pos, 'cash', 31500)])
        ->assertCreated()
        ->assertJsonPath('data.grand_total', '31500.00')
        ->assertJsonPath('data.change_total', '0.00');

    expect(Order::allTenants()->where('status', OrderStatus::Completed)->count())->toBe(10);

    // ---- Langkah 3: kas keluar operasional 20.000 (di bawah batas tanpa PIN 100.000) ----
    ncdApi($this, 'POST', '/cash-movements', ['id' => uuid(), 'type' => 'out', 'reason' => 'operational', 'amount' => 20000, 'note' => 'Beli es batu'])
        ->assertCreated()->assertJsonPath('data.approved_by', null);

    // ---- Langkah 4: rekap shift sebelum tutup ----
    // Omzet = 25.600 + 62.900 + 55.900 + 59.400 + 76.000 + 38.800 + 96.700 + 52.400 + 45.500 + 31.500 = 544.700
    // Tunai bersih (setelah kembalian) = 25.600 + 55.900 + 29.400 + 38.800 + 46.700 + 52.400 + 31.500 = 280.300
    // Kas seharusnya = 200.000 + 280.300 − 20.000 = 460.300
    $summary = ncdApi($this, 'GET', "/shifts/{$shiftId}/summary")
        ->assertOk()
        ->assertJsonPath('data.summary.order_count', 10)
        ->assertJsonPath('data.summary.open_bill_count', 0)
        ->assertJsonPath('data.summary.sales_total', '544700.00')
        ->assertJsonPath('data.summary.cash_sales', '280300.00')
        ->assertJsonPath('data.summary.cash_out', '20000.00')
        ->assertJsonPath('data.summary.expected_cash', '460300.00');

    $byMethod = collect($summary->json('data.summary.payment_methods'))->keyBy('category');
    // QRIS 62.900 + 76.000 + 50.000 = 188.900 (3×); debit 30.000 (1×); kredit 45.500 (1×); tunai 7×
    expect($byMethod['cash'])->toMatchArray(['count' => 7, 'amount' => '280300.00'])
        ->and($byMethod['qris'])->toMatchArray(['count' => 3, 'amount' => '188900.00'])
        ->and($byMethod['debit'])->toMatchArray(['count' => 1, 'amount' => '30000.00'])
        ->and($byMethod['credit'])->toMatchArray(['count' => 1, 'amount' => '45500.00']);

    // ---- Langkah 5: tutup shift dengan uang dihitung = kas seharusnya → selisih 0 ----
    ncdApi($this, 'POST', "/shifts/{$shiftId}/close", ['actual_cash' => 460300])
        ->assertOk()
        ->assertJsonPath('data.shift.status', 'closed')
        ->assertJsonPath('data.shift.expected_cash', '460300.00')
        ->assertJsonPath('data.shift.actual_cash', '460300.00')
        ->assertJsonPath('data.shift.difference', '0.00')
        ->assertJsonPath('data.summary.order_count', 10);

    // ---- Langkah 6: laporan owner hari ini cocok dengan hitungan tangan ----
    // Subtotal = 22.000 + 54.000 + 48.000 + 51.000 + 65.200 + 37.000 + 83.000 + 45.000 + 39.000 + 27.000 = 471.200
    // Diskon = 4.800 (item O5) + 3.700 (order O6) = 8.500; item terjual 9 kopi + 7 croissant + 6 teh = 22
    ncdReport($this, 'summary')
        ->assertOk()
        ->assertJsonPath('data.order_count', 10)
        ->assertJsonPath('data.revenue', '544700.00')
        ->assertJsonPath('data.average', '54470.00') // 544.700 ÷ 10
        ->assertJsonPath('data.items_sold', 22)
        ->assertJsonPath('data.subtotal_total', '471200.00')
        ->assertJsonPath('data.discount_total', '8500.00')
        ->assertJsonPath('data.void_count', 0);

    $methods = collect(ncdReport($this, 'payment-methods')->assertOk()->json('data.payment_methods'))->keyBy('category');
    expect($methods)->toHaveCount(4)
        ->and($methods['cash'])->toMatchArray(['count' => 7, 'amount' => '280300.00'])
        ->and($methods['qris'])->toMatchArray(['count' => 3, 'amount' => '188900.00'])
        ->and($methods['debit'])->toMatchArray(['count' => 1, 'amount' => '30000.00'])
        ->and($methods['credit'])->toMatchArray(['count' => 1, 'amount' => '45500.00']);

    // Per produk (line_total, sebelum diskon order):
    // Kopi qty 1+2+1+1+1+2+1 = 9; 22.000 + 54.000 + 27.000 + 22.000 + 22.000 + 44.000 + 27.000 = 218.000
    // Croissant qty 2+1+2+1+1 = 7; 48.000 + 24.000 + 43.200 + 24.000 + 24.000 = 163.200
    // Teh qty 1+1+3+1 = 6; 6 × 15.000 = 90.000
    ncdReport($this, 'products')
        ->assertOk()
        ->assertJsonCount(3, 'data.products')
        ->assertJsonPath('data.products.0', ['product_id' => $coffee->id, 'product_name' => 'Es Kopi Susu', 'qty' => 9, 'revenue' => '218000.00'])
        ->assertJsonPath('data.products.1', ['product_id' => $croissant->id, 'product_name' => 'Croissant', 'qty' => 7, 'revenue' => '163200.00'])
        ->assertJsonPath('data.products.2', ['product_id' => $tea->id, 'product_name' => 'Teh Tarik', 'qty' => 6, 'revenue' => '90000.00']);

    // ---- Langkah 7: stok produk yang dilacak berkurang tepat sebanyak qty terjual ----
    expect(stockOf($croissant)->stock_qty)->toBe(3)  // 10 − 7
        ->and(stockOf($tea)->stock_qty)->toBe(14)    // 20 − 6
        ->and((int) StockMovement::allTenants()->where('type', StockMovementType::Sale)->where('product_id', $croissant->id)->sum('qty_change'))->toBe(-7)
        ->and((int) StockMovement::allTenants()->where('type', StockMovementType::Sale)->where('product_id', $tea->id)->sum('qty_change'))->toBe(-6)
        // Kopi tidak dilacak stoknya: tanpa mutasi
        ->and(StockMovement::allTenants()->where('product_id', $coffee->id)->count())->toBe(0);
});

it('checkout dengan id yang sama dikirim ulang di tengah hari tetap satu order dan stok dipotong sekali', function () {
    ncdApi($this, 'POST', '/shifts', ['opening_cash' => 200000])->assertCreated();

    // 2 Croissant = 48.000 → 55.944 → 55.900
    $body = ['id' => uuid(), 'order_type' => 'takeaway', 'items' => [ncdItem($this->pos->croissant, 2)], 'payments' => [ncdPay($this->pos, 'qris', 55900)]];
    ncdApi($this, 'POST', '/checkout', $body)->assertCreated();
    ncdApi($this, 'POST', '/checkout', $body)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    expect(Order::allTenants()->count())->toBe(1)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(8);
    ncdReport($this, 'summary')->assertJsonPath('data.order_count', 1)->assertJsonPath('data.revenue', '55900.00');
});

it('laporan hari itu tidak memuat transaksi tenant lain di hari yang sama', function () {
    ncdApi($this, 'POST', '/shifts', ['opening_cash' => 0])->assertCreated();
    // 1 Croissant = 24.000 → 27.972 → 28.000
    ncdCheckout($this, [ncdItem($this->pos->croissant, 1)], [ncdPay($this->pos, 'cash', 28000)])->assertCreated();

    $other = posSetup();
    ncdApi($this, 'POST', '/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [ncdItem($other->croissant, 3)], 'payments' => [ncdPay($other, 'cash', 200000)],
    ], $other->token)->assertCreated();

    ncdReport($this, 'summary')->assertJsonPath('data.order_count', 1)->assertJsonPath('data.revenue', '28000.00');
    ncdReport($this, 'products')->assertJsonCount(1, 'data.products')->assertJsonPath('data.products.0.qty', 1);
});
