<?php

declare(strict_types=1);

use App\Actions\SelfOrder\AcceptSelfOrder;
use App\Actions\SelfOrder\RejectSelfOrder;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Filament\Dashboard\Resources\SelfOrders\Pages\ListSelfOrders;
use App\Filament\Dashboard\Resources\SelfOrders\SelfOrderResource;
use App\Models\SelfOrder;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/** Transaksi → Pesanan meja (ADR 0014): riwayat QR meja hanya baca, per status & outlet. */
function tableOrder(object $test, string $customer): SelfOrder
{
    return app(SubmitSelfOrder::class)->handle(SelfOrderContext::resolve($test->table->qr_token), [
        'id' => uuid(), 'customer_name' => $customer, 'notes' => 'Tanpa sendok',
        'items' => [['product_id' => $test->pos->coffee->id, 'qty' => 2, 'option_ids' => [$test->pos->large->id], 'notes' => 'less ice']],
    ], null, '127.0.0.1')['self_order'];
}

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
    RateLimiter::clear('self-order-ip:127.0.0.1');

    $this->pending = tableOrder($this, 'Andi');
    $this->rejected = app(RejectSelfOrder::class)->handle($this->pos->cashier, $this->pos->outlet->id, tableOrder($this, 'Bima'), 'Stok habis');
    $this->order = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, $accepted = tableOrder($this, 'Citra'));
    $this->accepted = $accepted->refresh();

    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

it('daftar pesanan meja: meja, pelanggan, item, status, alasan tolak, nomor order', function () {
    Livewire::test(ListSelfOrders::class)
        ->assertCanSeeTableRecords([$this->pending, $this->rejected, $this->accepted])
        ->assertSee('Meja 5')->assertSee('Andi')
        ->assertSee('Menunggu kasir')->assertSee('Ditolak')->assertSee('Diterima')
        ->assertSee('Stok habis')
        ->assertSee($this->order->order_number);

    $this->get(SelfOrderResource::getUrl())->assertOk()->assertSee('Pesanan meja');
});

it('tab status memisahkan pesanan', function () {
    Livewire::test(ListSelfOrders::class)
        ->set('activeTab', 'rejected')->assertCanSeeTableRecords([$this->rejected])->assertCanNotSeeTableRecords([$this->pending, $this->accepted])
        ->set('activeTab', 'pending')->assertCanSeeTableRecords([$this->pending])->assertCanNotSeeTableRecords([$this->rejected]);
});

it('detail: item dengan opsi & catatan, tautan ke penjualan', function () {
    expect(SelfOrderResource::lines($this->accepted))->toBe([
        ['name' => 'Es Kopi Susu', 'qty' => 2, 'options' => ['Large'], 'notes' => 'less ice'],
    ]);

    Livewire::test(ListSelfOrders::class)
        ->mountAction(TestAction::make('detail')->table($this->accepted))
        ->assertActionMounted(TestAction::make('detail')->table($this->accepted));

    $html = view('filament.dashboard.orders.table-order', [
        'selfOrder' => $this->accepted->load(['diningTable', 'order', 'handler']),
        'lines' => SelfOrderResource::lines($this->accepted),
        'orderUrl' => 'https://contoh.test/order',
    ])->render();

    expect($html)->toContain('Es Kopi Susu')->toContain('Large')->toContain('less ice')->toContain('Tanpa sendok')
        ->toContain($this->order->order_number)->toContain('Lihat penjualan')->toContain('Budi');
});

it('isolasi tenant: pesanan meja tenant lain tidak terlihat', function () {
    $other = posSetup();
    $foreign = TenantContext::run($other->tenantId, function () use ($other) {
        app(UpdateSelfOrderSettings::class)->handle($other->outlet, ['enabled' => true]);
        $table = app(SaveDiningTable::class)->handle(null, $other->outlet, ['name' => 'Meja Tetangga']);
        RateLimiter::clear('self-order-ip:127.0.0.1');

        return app(SubmitSelfOrder::class)->handle(SelfOrderContext::resolve($table->qr_token), [
            'id' => uuid(), 'customer_name' => 'Pelanggan Tetangga',
            'items' => [['product_id' => $other->coffee->id, 'qty' => 1, 'option_ids' => [$other->regular->id], 'notes' => null]],
        ], null, '127.0.0.1')['self_order'];
    });
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->owner);

    Livewire::test(ListSelfOrders::class)->assertCanNotSeeTableRecords([$foreign])->assertDontSee('Pelanggan Tetangga');
});

it('permission: kasir & supervisor ditolak; manager boleh; hanya baca', function () {
    $this->actingAs($this->pos->cashier);
    expect(SelfOrderResource::canViewAny())->toBeFalse();
    $this->get(SelfOrderResource::getUrl())->assertForbidden();

    $this->actingAs($this->pos->supervisor);
    expect(SelfOrderResource::canViewAny())->toBeFalse();

    $this->actingAs(User::factory()->manager()->create(['tenant_id' => $this->pos->tenantId]));
    expect(SelfOrderResource::canViewAny())->toBeTrue()
        ->and(SelfOrderResource::canCreate())->toBeFalse()
        ->and(SelfOrderResource::canEdit($this->pending))->toBeFalse()
        ->and(SelfOrderResource::canDelete($this->pending))->toBeFalse();
});
