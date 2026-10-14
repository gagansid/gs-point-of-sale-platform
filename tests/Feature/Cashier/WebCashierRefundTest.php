<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Shift;
use App\Models\User;
use App\Services\Report\DailySummaryBuilder;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Retur sebagian di kasir web + laporan (ADR 0018, Q92–Q94). 2 Croissant = 55.900; retur 1 = 27.950. */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($this->pos->webDevice, $this->pos->cashier)->create());
    // Transaksi dari tablet (shift lain) tetap bisa diretur di browser outlet yang sama
    $this->order = TenantContext::run($this->pos->tenantId, function (): Order {
        Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create();

        return app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
            'id' => uuid(), 'order_type' => 'takeaway',
            'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 2]],
            'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 60000]],
        ]))['order'];
    });
    $this->itemId = TenantContext::run($this->pos->tenantId, fn () => $this->order->items()->value('id'));
});

function webRefundBody(object $test, array $override = []): array
{
    return [
        'id' => (string) Str::uuid7(), 'reason' => 'Rusak', 'method' => 'cash', 'restock' => '1',
        'items' => [['order_item_id' => $test->itemId, 'qty' => 1]],
        'approver_user_id' => $test->pos->supervisor->id, 'approver_pin' => '123456',
        ...$override,
    ];
}

it('cari nomor → form retur → catat dengan PIN supervisor; riwayat retur tampil', function () {
    $this->get(route('cashier.refunds.search'))->assertOk()->assertSee('Retur transaksi');
    $this->get(route('cashier.refunds.search', ['number' => $this->order->order_number]))
        ->assertRedirect(route('cashier.refunds.create', $this->order->id));
    $this->get(route('cashier.refunds.create', $this->order->id))->assertOk()->assertSee('Croissant')->assertSee('Belum ada retur');

    $this->post(route('cashier.refunds.store', $this->order->id), webRefundBody($this))
        ->assertRedirect(route('cashier.refunds.create', $this->order->id))
        ->assertSessionHas('status', 'Retur Rp27.950 dicatat');

    $refund = OrderRefund::allTenants()->sole();
    expect($refund->shift_id)->not->toBe($this->order->shift_id)
        ->and($refund->device_id)->toBe($this->pos->webDevice->id);
    $this->get(route('cashier.refunds.create', $this->order->id))->assertOk()->assertSee('1 sudah diretur')->assertSee('Rp27.950');
});

it('nomor tidak ditemukan / tanpa PIN / qty berlebih ditolak', function () {
    $this->get(route('cashier.refunds.search', ['number' => 'TIDAK-ADA']))->assertSessionHasErrors('number');
    $this->post(route('cashier.refunds.store', $this->order->id), webRefundBody($this, ['approver_user_id' => null, 'approver_pin' => null]))
        ->assertSessionHasErrors('cashier');
    $this->post(route('cashier.refunds.store', $this->order->id), webRefundBody($this, ['items' => [['order_item_id' => $this->itemId, 'qty' => 5]]]))
        ->assertSessionHasErrors('cashier');

    expect(OrderRefund::allTenants()->count())->toBe(0);
});

it('order tenant lain → 404', function () {
    $foreign = posSetup();
    $foreignOrderId = TenantContext::run($foreign->tenantId, fn () => app(CheckoutOrder::class)->handle($foreign->cashier, $foreign->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $foreign->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $foreign->methods['cash']->id, 'amount' => 30000]],
    ]))['order']->id);

    $this->get(route('cashier.refunds.create', $foreignOrderId))->assertNotFound();
    $this->post(route('cashier.refunds.store', $foreignOrderId), webRefundBody($this))->assertNotFound();
});

it('laporan: ringkasan omzet setelah retur, panel Audit, ringkasan harian', function () {
    $this->post(route('cashier.refunds.store', $this->order->id), webRefundBody($this));

    TenantContext::set($this->pos->tenantId);
    $owner = User::factory()->owner()->create();
    CurrentOutlet::set($owner);
    [$start, $end] = [CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()];

    $summary = app(ReportService::class)->summary($start, $end);
    expect($summary['refund_count'])->toBe(1)
        ->and($summary['refund_total'])->toBe('27950.00')
        ->and($summary['net_revenue'])->toBe('27950.00');

    $audit = app(ReportService::class)->audit($start, $end);
    expect($audit['refund_count'])->toBe(1)
        ->and($audit['refunds'][0]['order_number'])->toBe($this->order->order_number)
        ->and($audit['refunds'][0]['approved_by'])->toBe('Sari')
        // Uang retur tidak dobel di kartu Kas keluar
        ->and($audit['cash_out_count'])->toBe(0);

    $daily = app(DailySummaryBuilder::class)->build($owner, $this->pos->outlet->localDate());
    expect($daily['totals']['refund_total'])->toBe('27950.00');
});
