<?php

declare(strict_types=1);

use App\Actions\Stock\CreateStockOpname;
use App\Actions\Stock\PostStockOpname;
use App\Actions\Stock\PostStockReceipt;
use App\Actions\Stock\ReceivePurchaseOrder;
use App\Actions\Stock\ReceiveStockTransfer;
use App\Actions\Stock\SaveOpnameCounts;
use App\Actions\Stock\SavePurchaseOrder;
use App\Actions\Stock\SaveStockReceipt;
use App\Actions\Stock\SaveStockTransfer;
use App\Actions\Stock\SaveSupplier;
use App\Actions\Stock\SendPurchaseOrder;
use App\Actions\Stock\SendStockTransfer;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
 * Skenario fase 3: siklus hidup stok satu produk di dua outlet (ADR 0015, ADR 0020, ADR 0021, SPEC Q80–Q83, Q97–Q101).
 *
 * Roti Tawar: lacak stok, harga jual 15.000, belum punya modal, stok 0 di semua outlet.
 * Roti Bakar: produk beresep (2 Roti Tawar), 30.000. Paket Sarapan: paket (1 Roti Bakar + 1 Roti Tawar), 40.000.
 * Outlet A = posSetup() JKT01; outlet B = BDG01 (perangkat & kasir sendiri). Rabu 14 Okt 2026 mulai 10:00 WIB,
 * jam maju 10 menit per langkah agar kartu stok berurutan.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 03:00:00');
    $this->pos = posSetup();
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create(['name' => 'Owner']));
    $this->ownerToken = userToken($this->owner, $this->pos->device);

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->outletB = Outlet::factory()->create(['code' => 'BDG01', 'name' => 'Outlet Bandung']);
        $this->deviceB = Device::factory()->forOutlet($this->outletB)->create(['device_uid' => 'kasir-bdg']);
        $this->cashierB = User::factory()->cashier()->forOutlet($this->outletB)->create(['name' => 'Rina']);
        Shift::factory()->forDevice($this->deviceB, $this->cashierB)->create();
        $this->supplier = app(SaveSupplier::class)->handle(null, ['name' => 'PT Roti Jaya']);
        $this->bread = Product::factory()->tracked(0)->create(['name' => 'Roti Tawar', 'price' => '15000.00', 'cost_price' => null]);
    });

    $this->tokenB = userToken($this->cashierB, $this->deviceB);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Jalankan Action di konteks tenant skenario, tanpa outlet sisa request HTTP sebelumnya
 * (OutletScope dari middleware request terakhir tetap aktif di proses test yang sama).
 */
function sltIn(object $test, Closure $callback): mixed
{
    CurrentOutlet::forget();

    return TenantContext::run($test->pos->tenantId, $callback);
}

/** Maju 10 menit (satu langkah skenario). */
function sltTick(): void
{
    Carbon::setTestNow(now()->addMinutes(10));
}

/** Simpan produk lewat API sebagai owner. */
function sltProduct(object $test, array $body): string
{
    freshAuth();

    return $test->withToken($test->ownerToken)->postJson('/api/v1/products', $body, apiHeaders())->assertCreated()->json('data.id');
}

/** Checkout tunai lewat API. */
function sltCheckout(object $test, string $token, array $items, ?string $id = null): TestResponse
{
    freshAuth();

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => $id ?? uuid(), 'order_type' => 'takeaway', 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 200000, 'tendered' => 200000]],
    ], apiHeaders());
}

/** Draf + posting stok masuk Roti Tawar di outlet A. */
function sltReceive(object $test, int $qty, string $unitCost): StockReceipt
{
    return sltIn($test, fn (): StockReceipt => app(PostStockReceipt::class)->handle(app(SaveStockReceipt::class)->handle(null, $test->pos->outlet, [
        'id' => uuid(), 'supplier_id' => $test->supplier->id, 'received_on' => '2026-10-14', 'invoice_number' => 'INV-'.$qty.$unitCost,
        'items' => [['product_id' => $test->bread->id, 'qty' => $qty, 'unit_cost' => $unitCost]],
    ]), $test->owner));
}

/** Laporan API sebagai owner untuk 14 Okt dan outlet tertentu / "all". */
function sltReport(object $test, string $report, string $outlet): array
{
    freshAuth();

    return $test->withToken(userToken($test->owner))
        ->getJson("/api/v1/reports/{$report}?from=2026-10-14&to=2026-10-14&outlet_id={$outlet}", apiHeaders())
        ->assertOk()
        ->json('data');
}

function sltBreadAt(object $test, string $outletId): int
{
    return stockOf($test->bread, $outletId)->stock_qty;
}

it('siklus stok sehari: stok masuk, jual, transfer, opname, PO, resep & paket — stok, kartu stok, HPP, laporan konsisten', function () {
    $a = $this->pos->outlet->id;
    $b = $this->outletB->id;

    // --- Langkah 1: stok masuk A 10 @ 10.000; belum ada modal → modal = harga beli 10.000
    $r1 = sltReceive($this, 10, '10000');
    expect($r1->items[0]->cost_before)->toBeNull()->and($r1->items[0]->cost_after)->toBe('10000.00')
        ->and(sltBreadAt($this, $a))->toBe(10);

    // --- Langkah 2: stok masuk A 10 @ 13.000; (10 × 10.000 + 10 × 13.000) ÷ 20 = 11.500
    sltTick();
    $r2 = sltReceive($this, 10, '13000');
    expect($r2->items[0]->cost_before)->toBe('10000.00')->and($r2->items[0]->cost_after)->toBe('11500.00')
        ->and($this->bread->refresh()->cost_price)->toBe('11500.00')
        ->and(sltBreadAt($this, $a))->toBe(20);

    // --- Langkah 3: kasir A menjual 3 Roti Tawar; HPP snapshot 11.500; stok A 20 − 3 = 17
    sltTick();
    $saleA = sltCheckout($this, $this->pos->token, [['product_id' => $this->bread->id, 'qty' => 3]])->assertCreated()->json('data.id');
    expect(OrderItem::allTenants()->where('order_id', $saleA)->sole()->unit_cost)->toBe('11500.00')
        ->and(sltBreadAt($this, $a))->toBe(17);

    // --- Langkah 4: transfer A → B 5 pcs: kirim (A 17 − 5 = 12), terima (B 0 + 5 = 5); modal tidak berubah
    sltTick();
    $transfer = sltIn($this, fn () => app(SaveStockTransfer::class)->handle(null, $this->owner, [
        'id' => uuid(), 'from_outlet_id' => $a, 'to_outlet_id' => $b, 'items' => [['product_id' => $this->bread->id, 'qty' => 5]],
    ]));
    sltIn($this, fn () => app(SendStockTransfer::class)->handle($transfer, $this->owner));
    expect(sltBreadAt($this, $a))->toBe(12)->and(sltBreadAt($this, $b))->toBe(0);
    sltTick();
    sltIn($this, fn () => app(ReceiveStockTransfer::class)->handle($transfer, $this->owner));
    expect(sltBreadAt($this, $b))->toBe(5)->and($this->bread->refresh()->cost_price)->toBe('11500.00');

    // --- Langkah 5: opname A, fisik 11 dari sistem 12 → selisih −1, nilai −1 × 11.500
    sltTick();
    $opname = sltIn($this, function () use ($a) {
        $sheet = app(CreateStockOpname::class)->handle(Outlet::query()->findOrFail($a), ['id' => uuid(), 'counted_on' => '2026-10-14']);
        app(SaveOpnameCounts::class)->handle($sheet, [$sheet->items->firstWhere('product_id', $this->bread->id)->id => '11']);

        return app(PostStockOpname::class)->handle($sheet, $this->owner);
    });
    $line = $opname->items->firstWhere('product_id', $this->bread->id);
    expect($line->difference)->toBe(-1)->and($line->difference_value)->toBe('-11500.00')
        ->and(sltBreadAt($this, $a))->toBe(11);

    // --- Langkah 6: PO outlet B 4 @ 14.000 → kirim → terima lewat stok masuk
    // Modal: stok positif semua outlet 11 + 5 = 16; (16 × 11.500 + 4 × 14.000) ÷ 20 = 240.000 ÷ 20 = 12.000
    sltTick();
    $po = sltIn($this, function () {
        $po = app(SavePurchaseOrder::class)->handle(null, $this->outletB, [
            'supplier_id' => $this->supplier->id, 'ordered_on' => '2026-10-14', 'expected_on' => '2026-10-14',
            'items' => [['product_id' => $this->bread->id, 'qty' => 4, 'unit_cost' => '14000']],
        ]);
        app(SendPurchaseOrder::class)->handle($po, $this->owner);
        // SendPurchaseOrder mengunci salinan baru: muat ulang status sebelum menerima
        app(PostStockReceipt::class)->handle(app(ReceivePurchaseOrder::class)->handle($po->refresh()), $this->owner);

        return $po->refresh();
    });
    expect($po->total_cost)->toBe('56000.00')->and($po->status)->toBe(PurchaseOrderStatus::Received)
        ->and(sltIn($this, fn () => $po->items()->value('received_qty')))->toBe(4)
        ->and($this->bread->refresh()->cost_price)->toBe('12000.00')
        ->and(sltBreadAt($this, $b))->toBe(9);

    // --- Langkah 7: resep & paket (ADR 0020), dijual di outlet B
    sltTick();
    $toast = sltProduct($this, ['name' => 'Roti Bakar', 'price' => 30000, 'components' => [['component_id' => $this->bread->id, 'qty' => 2]]]);
    $bundle = sltProduct($this, ['name' => 'Paket Sarapan', 'kind' => 'bundle', 'price' => 40000, 'components' => [
        ['component_id' => $toast, 'qty' => 1], ['component_id' => $this->bread->id, 'qty' => 1],
    ]]);
    $saleB = sltCheckout($this, $this->tokenB, [['product_id' => $toast, 'qty' => 1], ['product_id' => $bundle, 'qty' => 1]])
        ->assertCreated()->json('data.id');

    // Roti Bakar memakai 2, Paket memakai 2 (lewat Roti Bakar) + 1 = 3 → B 9 − 5 = 4
    // HPP snapshot: Roti Bakar 2 × 12.000 = 24.000; Paket 3 × 12.000 = 36.000
    $unitCosts = OrderItem::allTenants()->where('order_id', $saleB)->pluck('unit_cost', 'product_id');
    expect($unitCosts[$toast])->toBe('24000.00')->and($unitCosts[$bundle])->toBe('36000.00')
        ->and(sltBreadAt($this, $b))->toBe(4)
        // Produk beresep & paket tidak punya stok/riwayat sendiri
        ->and(StockMovement::allTenants()->whereIn('product_id', [$toast, $bundle])->exists())->toBeFalse();

    // --- Akhir: stok per outlet A 11, B 4; modal 12.000
    expect(sltBreadAt($this, $a))->toBe(11)->and(sltBreadAt($this, $b))->toBe(4)
        ->and($this->bread->refresh()->cost_price)->toBe('12000.00');

    // --- Kartu stok: urutan riwayat per outlet, qty_after = saldo berjalan
    $card = fn (string $outletId): array => StockMovement::allTenants()->where('product_id', $this->bread->id)->where('outlet_id', $outletId)
        ->orderBy('created_at')->orderBy('qty_after')->get()
        ->map(fn (StockMovement $m): array => [$m->type->value, $m->qty_change, $m->qty_after])->all();

    // A: +10 → 10, +10 → 20, −3 → 17, −5 → 12, −1 → 11
    expect($card($a))->toBe([
        ['purchase', 10, 10], ['purchase', 10, 20], ['sale', -3, 17], ['transfer_out', -5, 12], ['opname', -1, 11],
    ]);
    // B: +5 → 5, +4 → 9, lalu penjualan −5 total (Roti Bakar −2, Paket −3) → 4
    $cardB = $card($b);
    expect(array_slice($cardB, 0, 2))->toBe([['transfer_in', 5, 5], ['purchase', 4, 9]])
        ->and(collect(array_slice($cardB, 2))->every(fn (array $row): bool => $row[0] === 'sale'))->toBeTrue()
        ->and(array_sum(array_column(array_slice($cardB, 2), 1)))->toBe(-5)
        ->and(end($cardB)[2])->toBe(4);

    // Jumlah per tipe (semua outlet)
    $byType = StockMovement::allTenants()->where('product_id', $this->bread->id)->get()
        ->groupBy(fn (StockMovement $m): string => $m->type->value)->map->sum('qty_change')->sortKeys()->all();
    expect($byType)->toBe(['opname' => -1, 'purchase' => 24, 'sale' => -8, 'transfer_in' => 5, 'transfer_out' => -5]);

    // --- GET /reports/stock: pergerakan & nilai stok (stok × modal 12.000)
    $stockRow = fn (string $outlet): array => collect(sltReport($this, 'stock', $outlet)['stock'])->firstWhere('product_name', 'Roti Tawar');
    // A: masuk 20, terjual 3, transfer −5, opname −1 → +11; nilai 11 × 12.000 = 132.000
    expect($stockRow($a))->toMatchArray(['purchased' => 20, 'sold' => 3, 'transfer' => -5, 'opname' => -1, 'net_change' => 11, 'stock' => 11, 'stock_value' => '132000.00']);
    // B: masuk 4, terjual 5, transfer +5 → +4; nilai 4 × 12.000 = 48.000
    expect($stockRow($b))->toMatchArray(['purchased' => 4, 'sold' => 5, 'transfer' => 5, 'opname' => 0, 'net_change' => 4, 'stock' => 4, 'stock_value' => '48000.00']);
    // Semua: masuk 24, terjual 8, transfer bersih 0, opname −1 → +15; nilai 15 × 12.000 = 180.000
    expect($stockRow('all'))->toMatchArray(['purchased' => 24, 'sold' => 8, 'transfer' => 0, 'opname' => -1, 'net_change' => 15, 'stock' => 15, 'stock_value' => '180000.00']);
    expect(collect(sltReport($this, 'stock', 'all')['stock'])->pluck('product_name'))->not->toContain('Roti Bakar', 'Paket Sarapan');

    // --- GET /reports/purchases: 10 × 10.000 + 10 × 13.000 + 4 × 14.000 = 286.000
    expect(sltReport($this, 'purchases', 'all')['totals'])->toBe(['receipt_count' => 3, 'qty' => 24, 'total_cost' => '286000.00'])
        ->and(sltReport($this, 'purchases', $b)['totals'])->toBe(['receipt_count' => 1, 'qty' => 4, 'total_cost' => '56000.00']);

    // --- GET /reports/profit: HPP dari snapshot unit_cost
    $profit = sltReport($this, 'profit', 'all');
    $rows = collect($profit['products'])->keyBy('product_name');
    // Roti Tawar 3 × 15.000 = 45.000, HPP 3 × 11.500 = 34.500, laba 10.500
    expect($rows['Roti Tawar'])->toMatchArray(['net_sales' => '45000.00', 'cogs' => '34500.00', 'gross_profit' => '10500.00'])
        // Roti Bakar 30.000 − 24.000 = 6.000; Paket 40.000 − 36.000 = 4.000
        ->and($rows['Roti Bakar'])->toMatchArray(['net_sales' => '30000.00', 'cogs' => '24000.00', 'gross_profit' => '6000.00'])
        ->and($rows['Paket Sarapan'])->toMatchArray(['net_sales' => '40000.00', 'cogs' => '36000.00', 'gross_profit' => '4000.00'])
        // Total 115.000 − 94.500 = 20.500; margin 20.500 ÷ 115.000 = 17,83% → "17.8"
        ->and($profit['totals'])->toMatchArray(['net_sales' => '115000.00', 'cogs' => '94500.00', 'gross_profit' => '20500.00', 'margin' => '17.8'])
        ->and($profit['totals']['net_sales'])->toBe(sltReport($this, 'summary', 'all')['net_sales']);

    // Per outlet: A hanya Roti Tawar (HPP 34.500), B resep + paket (HPP 60.000)
    expect(sltReport($this, 'profit', $a)['totals']['cogs'])->toBe('34500.00')
        ->and(sltReport($this, 'profit', $b)['totals']['cogs'])->toBe('60000.00');
});

it('checkout paket ganda di outlet B hanya memotong komponen sekali', function () {
    sltReceive($this, 10, '10000');
    $toast = sltProduct($this, ['name' => 'Roti Bakar', 'price' => 30000, 'components' => [['component_id' => $this->bread->id, 'qty' => 2]]]);
    $bundle = sltProduct($this, ['name' => 'Paket Sarapan', 'kind' => 'bundle', 'price' => 40000, 'components' => [
        ['component_id' => $toast, 'qty' => 1], ['component_id' => $this->bread->id, 'qty' => 1],
    ]]);
    $id = uuid();

    sltCheckout($this, $this->tokenB, [['product_id' => $bundle, 'qty' => 2]], $id)->assertCreated();
    sltCheckout($this, $this->tokenB, [['product_id' => $bundle, 'qty' => 2]], $id)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    // 2 paket × 3 Roti Tawar = 6 → B 0 − 6 = −6; A tetap 10
    expect(Order::allTenants()->whereKey($id)->count())->toBe(1)
        ->and(sltBreadAt($this, $this->outletB->id))->toBe(-6)
        ->and(sltBreadAt($this, $this->pos->outlet->id))->toBe(10)
        ->and((int) StockMovement::allTenants()->where('type', StockMovementType::Sale)->sum('qty_change'))->toBe(-6);
});

it('modal rata-rata tertimbang memakai stok positif semua outlet, stok minus dihitung 0', function () {
    // B minus 3 (terjual tanpa stok) tidak menurunkan bobot: stok positif hanya A 10
    sltReceive($this, 10, '10000');
    sltCheckout($this, $this->tokenB, [['product_id' => $this->bread->id, 'qty' => 3]])->assertCreated();
    expect(sltBreadAt($this, $this->outletB->id))->toBe(-3);

    // (10 × 10.000 + 10 × 13.000) ÷ 20 = 11.500
    sltReceive($this, 10, '13000');
    expect($this->bread->refresh()->cost_price)->toBe('11500.00');
});

it('laporan stok bisnis lain tidak memuat produk & pergerakan bisnis ini', function () {
    sltReceive($this, 10, '10000');
    $other = posSetup();
    $otherOwner = TenantContext::run($other->tenantId, fn () => User::factory()->owner()->create());

    freshAuth();
    $names = $this->withToken(userToken($otherOwner))->getJson('/api/v1/reports/stock?from=2026-10-14&to=2026-10-14&outlet_id=all', apiHeaders())
        ->assertOk()->json('data.stock.*.product_name');
    expect($names)->not->toContain('Roti Tawar');

    freshAuth();
    assertApiError(
        $this->withToken(userToken($otherOwner))->getJson("/api/v1/reports/stock?outlet_id={$this->outletB->id}", apiHeaders()),
        'NOT_FOUND',
        404,
    );
});
