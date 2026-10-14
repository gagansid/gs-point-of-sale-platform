<?php

declare(strict_types=1);

use App\Actions\Stock\CancelPurchaseOrder;
use App\Actions\Stock\CancelStockReceipt;
use App\Actions\Stock\DeletePurchaseOrder;
use App\Actions\Stock\PostStockReceipt;
use App\Actions\Stock\ReceivePurchaseOrder;
use App\Actions\Stock\SavePurchaseOrder;
use App\Actions\Stock\SaveStockReceipt;
use App\Actions\Stock\SaveSupplier;
use App\Actions\Stock\SendPurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\StockReceipt;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Purchase order (ADR 0021, Q100–Q101): draf → kirim → terima sebagian/penuh lewat stok masuk → batal. */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    Carbon::setTestNow('2026-10-14 03:00:00');
    $this->outlet = Outlet::query()->findOrFail($this->pos->outlet->id);
    $this->owner = User::factory()->owner()->create();
    $this->supplier = app(SaveSupplier::class)->handle(null, ['name' => 'PT Roti Jaya']);
});

afterEach(fn () => Carbon::setTestNow());

function purchaseOrder(object $test, int $qty = 10, array $override = []): PurchaseOrder
{
    return app(SavePurchaseOrder::class)->handle(null, $test->outlet, [
        'supplier_id' => $test->supplier->id, 'ordered_on' => '2026-10-14', 'expected_on' => '2026-10-16',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty, 'unit_cost' => '9000']],
        ...$override,
    ]);
}

function poFails(Closure $call, string $message): void
{
    expect($call)->toThrow(BusinessException::class, $message);
}

it('draf → kirim → terima sebagian → terima sisa: qty diterima & status mengikuti stok masuk yang diposting', function () {
    $po = purchaseOrder($this);
    expect($po->number)->toBe('JKT01-PO-261014-0001')->and($po->total_cost)->toBe('90000.00')->and($po->status)->toBe(PurchaseOrderStatus::Draft);

    app(SendPurchaseOrder::class)->handle($po, $this->owner);
    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::Ordered);
    poFails(fn () => app(SavePurchaseOrder::class)->handle($po, $this->outlet, []), 'tidak bisa diubah');

    // Datang 6 dari 10
    $receipt = app(ReceivePurchaseOrder::class)->handle($po);
    expect($receipt->purchase_order_id)->toBe($po->id)->and($receipt->items()->value('qty'))->toBe(10);
    app(SaveStockReceipt::class)->handle($receipt, $this->outlet, [
        'supplier_id' => $this->supplier->id, 'received_on' => '2026-10-14',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 6, 'unit_cost' => '9500']],
    ]);
    $receipt->refresh()->forceFill(['purchase_order_id' => $po->id])->save();
    app(PostStockReceipt::class)->handle($receipt, $this->owner);

    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::Partial)
        ->and($po->items()->value('received_qty'))->toBe(6)
        ->and(stockOf($this->pos->croissant, $this->outlet->id)->stock_qty)->toBe(16);

    // Sisa 4 datang
    $rest = app(ReceivePurchaseOrder::class)->handle($po);
    expect($rest->items()->value('qty'))->toBe(4);
    app(PostStockReceipt::class)->handle($rest, $this->owner);
    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::Received);
    poFails(fn () => app(ReceivePurchaseOrder::class)->handle($po), 'Hanya PO');

    // Stok masuk dibatalkan → PO kembali diterima sebagian
    app(CancelStockReceipt::class)->handle($rest, $this->owner, 'Salah input');
    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::Partial)->and($po->items()->value('received_qty'))->toBe(6);
});

it('batalkan PO yang menunggu barang; draf dihapus, bukan dibatalkan', function () {
    $draft = purchaseOrder($this);
    poFails(fn () => app(CancelPurchaseOrder::class)->handle($draft, $this->owner, 'x'), 'Draf PO cukup dihapus');
    app(DeletePurchaseOrder::class)->handle($draft);
    expect(PurchaseOrder::query()->count())->toBe(0);

    $po = purchaseOrder($this);
    app(SendPurchaseOrder::class)->handle($po, $this->owner);
    poFails(fn () => app(CancelPurchaseOrder::class)->handle($po, $this->owner, ' '), 'Alasan wajib');
    app(CancelPurchaseOrder::class)->handle($po, $this->owner, 'Supplier kosong');
    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::Cancelled)->and($po->cancel_reason)->toBe('Supplier kosong');
    poFails(fn () => app(DeletePurchaseOrder::class)->handle($po), 'Hanya draf');
    poFails(fn () => app(ReceivePurchaseOrder::class)->handle($po), 'Hanya PO');
});

it('validasi: supplier wajib & aktif, produk lacak stok, produk sama digabung, isolasi tenant', function () {
    expect(fn () => purchaseOrder($this, override: ['supplier_id' => null]))->toThrow(ValidationException::class);
    poFails(fn () => purchaseOrder($this, override: ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'unit_cost' => '1']]]), 'tidak melacak stok');

    $po = purchaseOrder($this, override: ['items' => [
        ['product_id' => $this->pos->croissant->id, 'qty' => 2, 'unit_cost' => '9000'],
        ['product_id' => $this->pos->croissant->id, 'qty' => 3, 'unit_cost' => '9000'],
    ]]);
    expect($po->items()->count())->toBe(1)->and($po->items()->value('qty'))->toBe(5);

    $other = posSetup();
    TenantContext::set($this->pos->tenantId);
    poFails(fn () => purchaseOrder($this, override: ['items' => [['product_id' => $other->croissant->id, 'qty' => 1, 'unit_cost' => '1']]]), 'Produk tidak ditemukan');
    expect(TenantContext::run($other->tenantId, fn () => PurchaseOrder::query()->count()))->toBe(0);
});

it('dashboard: manager membuat, mengirim, dan menerima barang PO; supervisor tanpa menu PO', function () {
    Filament::setCurrentPanel('dashboard');
    $manager = User::factory()->manager()->forOutlet($this->outlet)->create();
    $this->actingAs($manager);
    CurrentOutlet::set($manager);

    expect(PurchaseOrderResource::canViewAny())->toBeTrue();
    $this->get(PurchaseOrderResource::getUrl())->assertOk();

    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm(['supplier_id' => $this->supplier->id, 'ordered_on' => '2026-10-14',
            'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 12, 'unit_cost' => '9000']]])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Draf PO disimpan');

    $po = PurchaseOrder::query()->sole();
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->callAction('send')->assertNotified('PO dikirim');
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->assertSee('Dikirim')->callAction('receive');

    expect(StockReceipt::query()->where('purchase_order_id', $po->id)->count())->toBe(1);

    $this->actingAs($this->pos->supervisor);
    expect(PurchaseOrderResource::canViewAny())->toBeFalse();
});
