<?php

declare(strict_types=1);

use App\Actions\Stock\CancelStockReceipt;
use App\Actions\Stock\DeleteStockReceipt;
use App\Actions\Stock\PostStockReceipt;
use App\Actions\Stock\SaveStockReceipt;
use App\Actions\Stock\SaveSupplier;
use App\Actions\Stock\SetSupplierActive;
use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\StockReceipts\Pages\CreateStockReceipt;
use App\Filament\Dashboard\Resources\StockReceipts\Pages\ViewStockReceipt;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Dashboard\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Dashboard\Resources\Suppliers\SupplierResource;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Stok masuk & supplier I1 (ADR 0015, Q79–Q80). Croissant lacak stok: 10 di outlet JKT01, modal 9.000.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    Carbon::setTestNow('2026-10-12 03:00:00');
    $this->croissant = $this->pos->croissant;
    $this->croissant->forceFill(['cost_price' => '9000.00'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->supplier = app(SaveSupplier::class)->handle(null, ['name' => 'PT Roti Jaya', 'phone' => '0812 1111 2222']);
});

afterEach(fn () => Carbon::setTestNow());

function receipt(object $test, array $items, array $override = []): StockReceipt
{
    return app(SaveStockReceipt::class)->handle(null, $test->pos->outlet, [
        'id' => uuid(), 'supplier_id' => $test->supplier->id, 'received_on' => '2026-10-12', 'invoice_number' => 'INV-77',
        'items' => $items, ...$override,
    ]);
}

function failsStock(Closure $call, string $message): void
{
    try {
        $call();
        test()->fail('Seharusnya ditolak');
    } catch (BusinessException $e) {
        expect($e->getMessage().' '.json_encode($e->details))->toContain($message);
    }
}

it('draf: nomor dokumen per outlet per hari, total beli, stok & modal belum berubah; id sama = draf sama', function () {
    $id = uuid();
    $draft = receipt($this, [['product_id' => $this->croissant->id, 'qty' => 5, 'unit_cost' => '12000']], ['id' => $id]);
    $again = receipt($this, [['product_id' => $this->croissant->id, 'qty' => 99, 'unit_cost' => '1']], ['id' => $id]);
    $second = receipt($this, [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '12000']]);

    expect($draft->number)->toBe('JKT01-SM-261012-0001')
        ->and($second->number)->toBe('JKT01-SM-261012-0002')
        ->and($again->id)->toBe($draft->id)->and($again->total_cost)->toBe('60000.00')
        ->and($draft->status)->toBe(StockDocumentStatus::Draft)
        ->and(stockOf($this->croissant, $this->pos->outlet->id)->stock_qty)->toBe(10)
        ->and($this->croissant->refresh()->cost_price)->toBe('9000.00');
});

it('posting: stok bertambah, riwayat purchase, modal rata-rata tertimbang dari stok semua outlet', function () {
    $other = Outlet::factory()->create(['tenant_id' => $this->pos->tenantId]);
    stockOf($this->croissant, $other->id)->forceFill(['stock_qty' => 5])->save();

    // (15 × 9.000 + 10 × 12.000) ÷ 25 = 10.200; baris kedua produk sama: (25 × 10.200 + 5 × 6.000) ÷ 30 = 9.500
    $posted = app(PostStockReceipt::class)->handle(receipt($this, [
        ['product_id' => $this->croissant->id, 'qty' => 10, 'unit_cost' => '12000'],
        ['product_id' => $this->croissant->id, 'qty' => 5, 'unit_cost' => '6000'],
    ]), $this->owner);

    expect($posted->status)->toBe(StockDocumentStatus::Posted)
        ->and($posted->posted_by)->toBe($this->owner->id)
        ->and(stockOf($this->croissant, $this->pos->outlet->id)->stock_qty)->toBe(25)
        ->and($this->croissant->refresh()->cost_price)->toBe('9500.00')
        ->and($posted->items[0]->cost_before)->toBe('9000.00')->and($posted->items[0]->cost_after)->toBe('10200.00')
        ->and($posted->items[1]->cost_after)->toBe('9500.00');

    $movements = StockMovement::query()->where('reference_id', $posted->id)->get();
    expect($movements)->toHaveCount(2)->and($movements->first()->type)->toBe(StockMovementType::Purchase);

    // Posting ulang = hasil sama
    app(PostStockReceipt::class)->handle($posted, $this->owner);
    expect(stockOf($this->croissant, $this->pos->outlet->id)->stock_qty)->toBe(25);
});

it('modal: stok minus dihitung 0, modal kosong = harga beli', function () {
    stockOf($this->croissant, $this->pos->outlet->id)->forceFill(['stock_qty' => -3])->save();
    app(PostStockReceipt::class)->handle(receipt($this, [['product_id' => $this->croissant->id, 'qty' => 4, 'unit_cost' => '11000']]), $this->owner);
    expect($this->croissant->refresh()->cost_price)->toBe('11000.00')->and(stockOf($this->croissant, $this->pos->outlet->id)->stock_qty)->toBe(1);

    $fresh = Product::factory()->tracked(0)->create(['cost_price' => null]);
    app(PostStockReceipt::class)->handle(receipt($this, [['product_id' => $fresh->id, 'qty' => 2, 'unit_cost' => '5000']]), $this->owner);
    expect($fresh->refresh()->cost_price)->toBe('5000.00');
});

it('batalkan: stok dikurangi lagi (riwayat purchase_void), modal tetap, alasan wajib; draf dihapus, bukan dibatalkan', function () {
    $posted = app(PostStockReceipt::class)->handle(receipt($this, [['product_id' => $this->croissant->id, 'qty' => 10, 'unit_cost' => '12000']]), $this->owner);
    $cost = $this->croissant->refresh()->cost_price;

    failsStock(fn () => app(CancelStockReceipt::class)->handle($posted, $this->owner, ' '), 'Alasan wajib');
    failsStock(fn () => app(SaveStockReceipt::class)->handle($posted, $this->pos->outlet, ['received_on' => '2026-10-12', 'items' => [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '1']]]), 'tidak bisa diubah');
    failsStock(fn () => app(DeleteStockReceipt::class)->handle($posted), 'Hanya draf');

    $cancelled = app(CancelStockReceipt::class)->handle($posted, $this->owner, 'Salah input qty');
    app(CancelStockReceipt::class)->handle($posted, $this->owner, 'Ulang');

    expect($cancelled->status)->toBe(StockDocumentStatus::Cancelled)->and($cancelled->cancel_reason)->toBe('Salah input qty')
        ->and(stockOf($this->croissant, $this->pos->outlet->id)->stock_qty)->toBe(10)
        ->and($this->croissant->refresh()->cost_price)->toBe($cost)
        ->and(StockMovement::query()->where('type', StockMovementType::PurchaseVoid)->count())->toBe(1);

    $draft = receipt($this, [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '1000']]);
    failsStock(fn () => app(CancelStockReceipt::class)->handle($draft, $this->owner, 'x'), 'hapus saja');
    app(DeleteStockReceipt::class)->handle($draft);
    expect(StockReceipt::query()->find($draft->id))->toBeNull();
});

it('validasi: tanggal masa depan, produk tanpa lacak stok, supplier nonaktif, qty 0, tanpa item', function () {
    failsStock(fn () => receipt($this, [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '1']], ['received_on' => '2026-10-13']), 'masa depan');
    failsStock(fn () => receipt($this, [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'unit_cost' => '1']]), 'tidak melacak stok');

    app(SetSupplierActive::class)->handle($this->supplier, false);
    failsStock(fn () => receipt($this, [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '1']]), 'nonaktif');

    expect(fn () => receipt($this, [['product_id' => $this->croissant->id, 'qty' => 0, 'unit_cost' => '1']], ['supplier_id' => null]))->toThrow(ValidationException::class);
    expect(fn () => receipt($this, [], ['supplier_id' => null]))->toThrow(ValidationException::class);
    failsStock(fn () => app(SaveSupplier::class)->handle(null, ['name' => 'pt roti jaya']), 'sudah dipakai');
    expect(StockReceipt::query()->count())->toBe(0);
});

it('isolasi tenant: produk, supplier, dan dokumen bisnis lain tidak bisa dipakai/dilihat', function () {
    $draft = receipt($this, [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '1000']]);
    $other = posSetup();
    TenantContext::set($other->tenantId);

    expect(StockReceipt::query()->find($draft->id))->toBeNull()->and(Supplier::query()->count())->toBe(0);
    failsStock(fn () => app(SaveStockReceipt::class)->handle(null, $other->outlet, [
        'received_on' => '2026-10-12', 'items' => [['product_id' => $this->croissant->id, 'qty' => 1, 'unit_cost' => '1']],
    ]), 'Produk tidak ditemukan');
    failsStock(fn () => app(SaveStockReceipt::class)->handle(null, $other->outlet, [
        'received_on' => '2026-10-12', 'supplier_id' => $this->supplier->id, 'items' => [['product_id' => $other->croissant->id, 'qty' => 1, 'unit_cost' => '1']],
    ]), 'Supplier tidak ditemukan');
});

it('dashboard: supervisor mencatat & memposting stok masuk; supplier hanya owner/manager; kasir tanpa akses', function () {
    Filament::setCurrentPanel('dashboard');
    $outlet = Outlet::query()->findOrFail($this->pos->outlet->id);
    $supervisor = $this->pos->supervisor;
    $this->actingAs($supervisor);
    CurrentOutlet::set($supervisor);

    expect(StockReceiptResource::canViewAny())->toBeTrue()->and(SupplierResource::canViewAny())->toBeFalse();
    $this->get(StockReceiptResource::getUrl())->assertOk();

    Livewire::test(CreateStockReceipt::class)
        ->fillForm([
            'received_on' => '2026-10-12', 'supplier_id' => $this->supplier->id, 'invoice_number' => 'INV-9',
            'items' => [['product_id' => $this->croissant->id, 'qty' => 6, 'unit_cost' => '10000']],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Draf stok masuk disimpan');

    $receipt = StockReceipt::query()->sole();
    expect($receipt->total_cost)->toBe('60000.00')->and($receipt->created_by)->toBe($supervisor->id);

    Livewire::test(ViewStockReceipt::class, ['record' => $receipt->id])
        ->assertSee('INV-9')
        ->callAction('post')
        ->assertNotified('Stok masuk diposting');
    expect($receipt->refresh()->status)->toBe(StockDocumentStatus::Posted)->and(stockOf($this->croissant, $this->pos->outlet->id)->stock_qty)->toBe(16);

    $this->actingAs(User::factory()->manager()->forOutlet($outlet)->create());
    expect(SupplierResource::canViewAny())->toBeTrue();
    Livewire::test(ManageSuppliers::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'CV Susu Segar', 'email' => 'Halo@Susu.id'])
        ->assertHasNoActionErrors();
    expect(Supplier::query()->where('name', 'CV Susu Segar')->value('email'))->toBe('halo@susu.id');

    $this->actingAs($this->pos->cashier);
    expect(StockReceiptResource::canViewAny())->toBeFalse();
});
