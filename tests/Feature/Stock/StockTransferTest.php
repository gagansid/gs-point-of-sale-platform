<?php

declare(strict_types=1);

use App\Actions\Stock\CancelStockTransfer;
use App\Actions\Stock\DeleteStockTransfer;
use App\Actions\Stock\ReceiveStockTransfer;
use App\Actions\Stock\SaveStockTransfer;
use App\Actions\Stock\SendStockTransfer;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Transfer antar outlet I3 (ADR 0015, Q82). Croissant lacak stok: 10 di JKT01 (asal), 0 di Cilandak (tujuan). */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    Carbon::setTestNow('2026-10-12 03:00:00');
    $this->from = $this->pos->outlet;
    $this->to = Outlet::factory()->create(['tenant_id' => $this->pos->tenantId, 'name' => 'Cilandak', 'code' => 'CLD01']);
    $this->owner = User::factory()->owner()->create();
});

afterEach(fn () => Carbon::setTestNow());

function transfer(object $test, int $qty = 4, array $override = [], ?User $by = null): StockTransfer
{
    return app(SaveStockTransfer::class)->handle(null, $by ?? $test->owner, [
        'id' => uuid(), 'from_outlet_id' => $test->from->id, 'to_outlet_id' => $test->to->id,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]], ...$override,
    ]);
}

function stockAt(object $test, Outlet $outlet): int
{
    return stockOf($test->pos->croissant, $outlet->id)->stock_qty;
}

it('draf → kirim (stok asal berkurang) → terima (stok tujuan bertambah); riwayat di kedua outlet; nomor TR outlet asal', function () {
    $draft = transfer($this);
    expect($draft->number)->toBe('JKT01-TR-261012-0001')->and($draft->status)->toBe(StockTransferStatus::Draft)
        ->and(stockAt($this, $this->from))->toBe(10);

    app(SendStockTransfer::class)->handle($draft, $this->owner);
    app(SendStockTransfer::class)->handle($draft, $this->owner);
    expect($draft->refresh()->status)->toBe(StockTransferStatus::InTransit)
        ->and(stockAt($this, $this->from))->toBe(6)->and(stockAt($this, $this->to))->toBe(0);

    $received = app(ReceiveStockTransfer::class)->handle($draft, $this->owner);
    app(ReceiveStockTransfer::class)->handle($draft, $this->owner);
    expect($received->status)->toBe(StockTransferStatus::Received)->and($received->items[0]->qty_received)->toBe(4)
        ->and(stockAt($this, $this->to))->toBe(4);

    expect(StockMovement::query()->where('reference_id', $draft->id)->pluck('type')->map->value->sort()->values()->all())
        ->toBe(['transfer_in', 'transfer_out']);
});

it('terima kurang dari dikirim: hanya qty diterima masuk tujuan, selisih tidak kembali ke asal; qty di luar batas ditolak', function () {
    $sent = app(SendStockTransfer::class)->handle(transfer($this, 5), $this->owner);
    $item = $sent->items[0];

    expect(fn () => app(ReceiveStockTransfer::class)->handle($sent, $this->owner, [$item->id => 6]))->toThrow(BusinessException::class, '0–5');
    expect(fn () => app(ReceiveStockTransfer::class)->handle($sent, $this->owner, [$item->id => -1]))->toThrow(BusinessException::class);

    app(ReceiveStockTransfer::class)->handle($sent, $this->owner, [$item->id => 3]);
    expect(stockAt($this, $this->to))->toBe(3)->and(stockAt($this, $this->from))->toBe(5)
        ->and($item->refresh()->qty_received)->toBe(3);
});

it('batal hanya saat dalam perjalanan: stok kembali ke asal; draf dihapus; sudah diterima tidak bisa dibatalkan', function () {
    $sent = app(SendStockTransfer::class)->handle(transfer($this), $this->owner);
    expect(fn () => app(CancelStockTransfer::class)->handle($sent, $this->owner, ''))->toThrow(BusinessException::class, 'Alasan wajib');
    app(CancelStockTransfer::class)->handle($sent, $this->owner, 'Kurir batal');
    expect($sent->refresh()->status)->toBe(StockTransferStatus::Cancelled)->and(stockAt($this, $this->from))->toBe(10)
        ->and(StockMovement::query()->where('type', StockMovementType::TransferCancel)->count())->toBe(1);
    expect(fn () => app(ReceiveStockTransfer::class)->handle($sent, $this->owner))->toThrow(BusinessException::class, 'dalam perjalanan');

    $draft = transfer($this);
    expect(fn () => app(CancelStockTransfer::class)->handle($draft, $this->owner, 'x'))->toThrow(BusinessException::class, 'hapus saja');
    app(DeleteStockTransfer::class)->handle($draft, $this->owner);
    expect(StockTransfer::query()->find($draft->id))->toBeNull();

    $done = app(ReceiveStockTransfer::class)->handle(app(SendStockTransfer::class)->handle(transfer($this), $this->owner), $this->owner);
    expect(fn () => app(CancelStockTransfer::class)->handle($done, $this->owner, 'x'))->toThrow(BusinessException::class, 'sudah diterima');
    expect(fn () => app(SaveStockTransfer::class)->handle($done, $this->owner, ['from_outlet_id' => $this->from->id, 'to_outlet_id' => $this->to->id, 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]]]))
        ->toThrow(BusinessException::class, 'tidak bisa diubah');
});

it('validasi & akses per sisi: asal = tujuan, produk tanpa lacak stok, manager outlet tujuan tidak bisa kirim tapi bisa terima', function () {
    expect(fn () => transfer($this, override: ['to_outlet_id' => $this->from->id]))->toThrow(ValidationException::class);
    expect(fn () => transfer($this, override: ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1]]]))->toThrow(BusinessException::class, 'tidak melacak stok');

    $managerTo = User::factory()->manager()->forOutlet($this->to)->create();
    expect(fn () => transfer($this, by: $managerTo))->toThrow(BusinessException::class, 'bukan outlet Anda');

    $draft = transfer($this);
    expect(fn () => app(SendStockTransfer::class)->handle($draft, $managerTo))->toThrow(BusinessException::class);
    app(SendStockTransfer::class)->handle($draft, $this->owner);
    app(ReceiveStockTransfer::class)->handle($draft, $managerTo);
    expect($draft->refresh()->received_by)->toBe($managerTo->id);

    // Manager outlet asal saja tidak bisa menerima di tujuan
    $managerFrom = User::factory()->manager()->forOutlet($this->from)->create();
    $second = app(SendStockTransfer::class)->handle(transfer($this, by: $managerFrom), $managerFrom);
    expect(fn () => app(ReceiveStockTransfer::class)->handle($second, $managerFrom))->toThrow(BusinessException::class);
});

it('isolasi tenant: transfer & outlet bisnis lain tidak terlihat/terpakai', function () {
    $draft = transfer($this);
    $other = posSetup();
    TenantContext::set($other->tenantId);
    $otherOwner = User::factory()->owner()->create();

    expect(StockTransfer::query()->find($draft->id))->toBeNull();
    expect(fn () => app(SaveStockTransfer::class)->handle(null, $otherOwner, [
        'from_outlet_id' => $other->outlet->id, 'to_outlet_id' => $this->to->id, 'items' => [['product_id' => $other->croissant->id, 'qty' => 1]],
    ]))->toThrow(BusinessException::class, 'Outlet tujuan tidak ditemukan');
});

it('dashboard: owner membuat, mengirim, menerima transfer; supervisor tanpa menu', function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    expect(StockTransferResource::canViewAny())->toBeTrue();
    Livewire::test(CreateStockTransfer::class)
        ->fillForm(['from_outlet_id' => $this->from->id, 'to_outlet_id' => $this->to->id, 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 2]]])
        ->call('create')->assertHasNoFormErrors();
    $transfer = StockTransfer::query()->sole();

    Livewire::test(ViewStockTransfer::class, ['record' => $transfer->id])
        ->assertActionVisible('send')->callAction('send')->assertHasNoActionErrors();
    expect($transfer->refresh()->status)->toBe(StockTransferStatus::InTransit);
    $item = $transfer->items()->sole();
    Livewire::test(ViewStockTransfer::class, ['record' => $transfer->id])
        ->assertActionVisible('receive')->callAction('receive', ['received' => [$item->id => 2]])->assertHasNoActionErrors();
    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Received);

    expect(stockAt($this, $this->to))->toBe(2)->and(stockAt($this, $this->from))->toBe(8);

    $this->actingAs($this->pos->supervisor);
    expect(StockTransferResource::canViewAny())->toBeFalse();
});
