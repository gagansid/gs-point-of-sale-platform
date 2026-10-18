<?php

declare(strict_types=1);

use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateCreditProgram;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Enums\CashMovementReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\ReceivableEntryType;
use App\Enums\StockMovementType;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\ReceivableEntry;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Kontrak uang kasir web (Q54): kiriman ganda retur/void/pecah bill/kas/kasbon hanya mencatat sekali,
 * data tenant lain 404, perangkat/shift lain ditolak. Croissant 24.000 → total 28.000 (service 5%, pajak 11%,
 * pembulatan 100).
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    webCashierLogin($this, $this->pos, $this->pos->cashier);
    $this->webShift = TenantContext::run($this->pos->tenantId, fn (): Shift => Shift::factory()
        ->forDevice($this->pos->webDevice, $this->pos->cashier)->create(['opening_cash' => '500000.00']));
});

/** Order selesai di browser kasir (shift web), dibayar tunai. */
function wmcCompletedOrder(object $test, int $qty = 1): Order
{
    return TenantContext::run($test->pos->tenantId, fn (): Order => app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->webDevice, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 100000]],
    ]))['order']);
}

/** Open bill di browser kasir. */
function wmcOpenBill(object $test, int $qty = 2, string $table = 'Meja 1'): Order
{
    return TenantContext::run($test->pos->tenantId, fn (): Order => app(SaveOpenBill::class)->handle($test->pos->cashier, $test->pos->webDevice, uuid(), OpenBillData::fromArray([
        'order_type' => 'dine_in', 'table_label' => $table,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
    ]))['order']);
}

/** Open bill tenant lain (device tablet tenant itu). */
function wmcForeignOpenBill(): Order
{
    $foreign = posSetup();

    return TenantContext::run($foreign->tenantId, fn (): Order => app(SaveOpenBill::class)->handle($foreign->cashier, $foreign->device, uuid(), OpenBillData::fromArray([
        'items' => [['product_id' => $foreign->croissant->id, 'qty' => 2]],
    ]))['order']);
}

function wmcItemId(object $test, Order $order): string
{
    return TenantContext::run($test->pos->tenantId, fn (): string => (string) $order->items()->value('id'));
}

describe('cashier.refunds.store', function () {
    it('kiriman ganda dengan id form sama hanya mencatat satu retur dan satu kas keluar', function () {
        $order = wmcCompletedOrder($this, 2);
        // Form create memuat refundId sekali pakai (idempotency key)
        $this->get(route('cashier.refunds.create', $order->id))->assertOk()->assertSee('name="id"', false);

        $body = [
            'id' => (string) Str::uuid7(), 'reason' => 'Rusak', 'method' => 'cash', 'restock' => '1',
            'items' => [['order_item_id' => wmcItemId($this, $order), 'qty' => 1]],
            'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ];

        $this->post(route('cashier.refunds.store', $order->id), $body)->assertSessionHas('status', 'Retur Rp28.000 dicatat');
        $this->post(route('cashier.refunds.store', $order->id), $body)
            ->assertRedirect(route('cashier.refunds.create', $order->id))
            ->assertSessionHas('status', 'Retur sudah tercatat');

        expect(OrderRefund::allTenants()->count())->toBe(1)
            ->and(CashMovement::allTenants()->where('reason', CashMovementReason::Refund)->count())->toBe(1)
            // Stok 10 − 2 terjual + 1 restock (sekali)
            ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(9);
    });
});

describe('cashier.orders.void', function () {
    it('void dua kali: order di-void sekali, stok kembali sekali', function () {
        $order = wmcCompletedOrder($this, 2);
        expect(stockOf($this->pos->croissant)->stock_qty)->toBe(8);

        $body = ['reason' => 'Salah input', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456'];
        $this->post(route('cashier.orders.void', $order->id), $body)->assertRedirect(route('cashier.orders.show', $order->id));
        $this->post(route('cashier.orders.void', $order->id), $body)->assertRedirect(route('cashier.orders.show', $order->id))
            ->assertSessionHasNoErrors();

        $order = Order::allTenants()->findOrFail($order->id);
        expect($order->status)->toBe(OrderStatus::Voided)
            ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10)
            ->and(StockMovement::allTenants()->where('type', StockMovementType::VoidReturn)->count())->toBe(1);
    });
});

describe('cashier.orders.split', function () {
    it('open bill tenant lain → 404', function () {
        $foreignBill = wmcForeignOpenBill();
        $itemId = TenantContext::run($foreignBill->tenant_id, fn (): string => (string) $foreignBill->items()->value('id'));

        $this->post(route('cashier.orders.split', $foreignBill->id), [
            'new_order_id' => (string) Str::uuid7(), 'items' => [['order_item_id' => $itemId, 'qty' => 1]],
        ])->assertNotFound();

        expect(Order::allTenants()->count())->toBe(1);
    });

    it('kiriman ulang dengan new_order_id sama hanya membuat satu bill baru', function () {
        $bill = wmcOpenBill($this, 3);
        $body = ['new_order_id' => (string) Str::uuid7(), 'items' => [['order_item_id' => wmcItemId($this, $bill), 'qty' => 1]]];

        $this->post(route('cashier.orders.split', $bill->id), $body)->assertRedirect(route('cashier.orders.show', $body['new_order_id']));
        $this->post(route('cashier.orders.split', $bill->id), $body)->assertRedirect(route('cashier.orders.show', $body['new_order_id']));

        expect(Order::allTenants()->count())->toBe(2)
            ->and(TenantContext::run($this->pos->tenantId, fn (): int => (int) $bill->items()->sum('qty')))->toBe(2)
            ->and(TenantContext::run($this->pos->tenantId, fn (): int => (int) Order::query()->findOrFail($body['new_order_id'])->items()->sum('qty')))->toBe(1);
    });

    it('order selesai / void tidak bisa dipecah', function () {
        $completed = wmcCompletedOrder($this, 2);
        $voided = wmcCompletedOrder($this, 2);
        $this->post(route('cashier.orders.void', $voided->id), ['reason' => 'x', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456']);

        foreach ([$completed, $voided] as $order) {
            $this->post(route('cashier.orders.split', $order->id), [
                'new_order_id' => (string) Str::uuid7(), 'items' => [['order_item_id' => wmcItemId($this, $order), 'qty' => 1]],
            ])->assertSessionHasErrors('cashier');
        }

        expect(Order::allTenants()->count())->toBe(2);
    });
});

describe('cashier.orders.merge', function () {
    it('bill tujuan tenant lain → 404', function () {
        $foreignBill = wmcForeignOpenBill();
        $own = wmcOpenBill($this);

        $this->post(route('cashier.orders.merge', $foreignBill->id), ['source_order_id' => $own->id])->assertNotFound();

        expect(Order::allTenants()->findOrFail($own->id)->status)->toBe(OrderStatus::Open);
    });

    it('bill sumber tenant lain → 404, bill sumber tidak berubah', function () {
        $foreignBill = wmcForeignOpenBill();
        $own = wmcOpenBill($this);

        $this->post(route('cashier.orders.merge', $own->id), ['source_order_id' => $foreignBill->id])->assertNotFound();

        expect(Order::allTenants()->findOrFail($foreignBill->id)->status)->toBe(OrderStatus::Open)
            ->and(TenantContext::run($this->pos->tenantId, fn (): int => (int) $own->items()->sum('qty')))->toBe(2);
    });

    it('order selesai sebagai sumber atau tujuan ditolak', function () {
        $own = wmcOpenBill($this);
        $completed = wmcCompletedOrder($this);

        $this->post(route('cashier.orders.merge', $own->id), ['source_order_id' => $completed->id])->assertSessionHasErrors('cashier');
        $this->post(route('cashier.orders.merge', $completed->id), ['source_order_id' => $own->id])->assertSessionHasErrors('cashier');

        expect(Order::allTenants()->findOrFail($completed->id)->status)->toBe(OrderStatus::Completed)
            ->and(Order::allTenants()->findOrFail($own->id)->status)->toBe(OrderStatus::Open);
    });
});

describe('cashier.shift.opening-cash', function () {
    it('kasir tanpa izin shift.edit_opening_cash dan tanpa PIN ditolak', function () {
        expect($this->pos->cashier->can('shift.edit_opening_cash'))->toBeFalse();

        $this->post(route('cashier.shift.opening-cash'), ['opening_cash' => '450000', 'opening_cash_note' => 'Salah hitung'])
            ->assertSessionHasErrors('cashier');

        expect($this->webShift->refresh()->opening_cash)->toBe('500000.00')
            ->and($this->webShift->opening_cash_adjusted_at)->toBeNull();
    });

    it('kiriman ganda hanya mengoreksi sekali (nilai semula & waktu koreksi tidak berubah)', function () {
        $body = [
            'opening_cash' => '450000', 'opening_cash_note' => 'Salah hitung',
            'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456',
        ];

        $this->post(route('cashier.shift.opening-cash'), $body)->assertRedirect(route('cashier.shift'));
        $first = $this->webShift->refresh()->opening_cash_adjusted_at;
        $this->travel(5)->minutes();
        $this->post(route('cashier.shift.opening-cash'), $body)->assertRedirect(route('cashier.shift'));

        $shift = $this->webShift->refresh();
        expect($shift->opening_cash)->toBe('450000.00')
            ->and($shift->carried_cash)->toBe('500000.00')
            ->and($shift->opening_cash_adjusted_at?->equalTo($first))->toBeTrue();
    });
});

describe('cashier.shift.cash-movements.store', function () {
    it('browser tanpa shift tidak bisa mencatat ke shift perangkat lain di outlet yang sama', function () {
        $tabletShift = TenantContext::run($this->pos->tenantId, fn (): Shift => Shift::factory()->forDevice($this->pos->device, $this->pos->supervisor)->create(['opening_cash' => '300000.00']));
        // Shift web ditutup: browser ini tidak punya shift terbuka
        $this->webShift->forceFill(['open_device_key' => null, 'closed_at' => now()])->save();

        $this->post(route('cashier.shift.cash-movements.store'), [
            'id' => (string) Str::uuid7(), 'type' => 'out', 'reason' => 'operational', 'amount' => '10000', 'note' => 'Galon',
        ])->assertSessionHasErrors('cashier');

        expect(CashMovement::allTenants()->where('shift_id', $tabletShift->id)->count())->toBe(0)
            ->and(CashMovement::allTenants()->count())->toBe(0);
    });

    it('kiriman ulang id kas milik outlet lain ditolak, tidak membuat atau mengembalikan data', function () {
        $otherOutlet = TenantContext::run($this->pos->tenantId, fn (): Outlet => Outlet::factory()->create(['code' => 'BDG01']));
        $otherDevice = TenantContext::run($this->pos->tenantId, fn (): Device => Device::factory()->forOutlet($otherOutlet)->create());
        $otherShift = TenantContext::run($this->pos->tenantId, fn (): Shift => Shift::factory()->forDevice($otherDevice, $this->pos->supervisor)->create());
        $movement = TenantContext::run($this->pos->tenantId, function () use ($otherShift): CashMovement {
            $m = new CashMovement([
                'outlet_id' => $otherShift->outlet_id, 'shift_id' => $otherShift->id, 'user_id' => $this->pos->supervisor->id,
                'type' => 'in', 'reason' => 'operational', 'amount' => '5000.00', 'note' => 'Tukar',
            ]);
            $m->id = (string) Str::uuid7();
            $m->save();

            return $m;
        });

        $this->post(route('cashier.shift.cash-movements.store'), [
            'id' => $movement->id, 'type' => 'in', 'reason' => 'operational', 'amount' => '5000',
        ])->assertSessionHasErrors('cashier')->assertSessionMissing('status');

        expect(CashMovement::allTenants()->count())->toBe(1)
            ->and(CashMovement::allTenants()->where('shift_id', $this->webShift->id)->count())->toBe(0);
    });
});

describe('cashier.receivables.pay', function () {
    beforeEach(function () {
        TenantContext::run($this->pos->tenantId, function (): void {
            app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
            app(UpdateCreditProgram::class)->handle(true, '100000');
            $this->member = Member::factory()->create(['name' => 'Rina']);
            app(SetMemberPin::class)->handle($this->member, '4821');
            $credit = PaymentMethod::query()->where('category', PaymentCategory::Receivable)->sole();

            app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->webDevice, CheckoutData::fromArray([
                'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $this->member->id, 'member_pin' => '4821',
                'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
                'payments' => [['id' => uuid(), 'payment_method_id' => $credit->id, 'amount' => 28000]],
            ]));
        });
        expect($this->member->refresh()->receivable_balance)->toBe('28000.00');
    });

    it('nominal di atas sisa kasbon ditolak', function () {
        $this->post(route('cashier.receivables.pay', $this->member->id), [
            'id' => (string) Str::uuid7(), 'amount' => '28001', 'payment_method_id' => $this->pos->methods['cash']->id,
        ])->assertSessionHasErrors('cashier');

        expect($this->member->refresh()->receivable_balance)->toBe('28000.00')
            ->and(ReceivableEntry::allTenants()->where('type', ReceivableEntryType::Payment)->count())->toBe(0);
    });

    it('member tenant lain → 404', function () {
        $foreign = posSetup();
        $foreignMember = TenantContext::run($foreign->tenantId, fn (): Member => Member::factory()->create());

        $this->post(route('cashier.receivables.pay', $foreignMember->id), [
            'id' => (string) Str::uuid7(), 'amount' => '1000', 'payment_method_id' => $this->pos->methods['cash']->id,
        ])->assertNotFound();
    });

    it('kiriman ganda dengan id sama hanya mencatat satu pelunasan dan satu kas masuk', function () {
        $body = ['id' => (string) Str::uuid7(), 'amount' => '10000', 'payment_method_id' => $this->pos->methods['cash']->id];

        $this->post(route('cashier.receivables.pay', $this->member->id), $body)->assertSessionHas('status', 'Pelunasan Rina Rp10.000 dicatat');
        $this->post(route('cashier.receivables.pay', $this->member->id), $body)->assertSessionHas('status', 'Pelunasan sudah tercatat');

        expect($this->member->refresh()->receivable_balance)->toBe('18000.00')
            ->and(ReceivableEntry::allTenants()->where('type', ReceivableEntryType::Payment)->count())->toBe(1)
            ->and(CashMovement::allTenants()->where('reason', CashMovementReason::ReceivablePayment)->count())->toBe(1);
    });
});
