<?php

declare(strict_types=1);

use App\Actions\Fulfillment\AdvanceOrderFulfillment;
use App\Actions\Fulfillment\AutoCompleteFulfillment;
use App\Actions\Fulfillment\UndoOrderFulfillment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\SelfOrder\CheckoutSelfOrder;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Actions\User\UpdateRolePermissions;
use App\Enums\ErrorCode;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Livewire\Cashier\FulfillmentQueue;
use App\Livewire\Cashier\SelfOrders;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Pengantaran pesanan meja sampai diantar (ADR 0027 §4, K4, K8, K9; SPEC Q137 T5). */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 05:00:00');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 4', 'area' => 'Teras']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
    $this->owner = User::factory()->owner()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** Pesanan meja yang sudah lunas tunai di kasir (API) → otomatis Antre. */
function paidTableOrder(object $test, string $name = 'Andi', ?string $tableId = null): Order
{
    $table = $tableId !== null ? DiningTable::query()->findOrFail($tableId) : $test->table;
    $order = app(CheckoutSelfOrder::class)->handle(SelfOrderContext::resolve($table->qr_token), [
        'id' => uuid(), 'customer_name' => $name, 'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
    ], null, '10.9.'.random_int(0, 255).'.'.random_int(0, 255))['order'];

    freshAuth();
    $test->withToken($test->pos->token)->postJson("/api/v1/orders/{$order->id}/payments", [
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 28000]],
    ], apiHeaders())->assertCreated();
    TenantContext::set($test->pos->tenantId);

    return $order->refresh();
}

function queuePage(object $test, User $user): Testable
{
    $device = webCashierLogin($test, $test->pos, $user);
    CurrentOutlet::set($user, $test->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);

    return Livewire::test(FulfillmentQueue::class);
}

it('lunas (kasir, webhook, poin) = satu titik: pesanan meja masuk Antre; order kasir biasa tanpa tahap', function () {
    $order = paidTableOrder($this);
    expect($order->status)->toBe(OrderStatus::Completed)->and($order->fulfillment_status)->toBe(FulfillmentStatus::Queued);

    $pos = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 28000]],
    ]));
    expect(Order::query()->findOrFail($pos['order']->id)->fulfillment_status)->toBeNull();
});

it('maju bertahap & boleh melompat ke depan; idempotent; tidak pernah mundur; waktu tiap tahap tercatat', function () {
    $advance = app(AdvanceOrderFulfillment::class);
    $order = paidTableOrder($this);

    Carbon::setTestNow('2026-10-08 05:03:00');
    $advance->handle($this->pos->cashier, $order, FulfillmentStatus::Preparing);
    Carbon::setTestNow('2026-10-08 05:08:00');
    $advance->handle($this->pos->cashier, $order, FulfillmentStatus::Ready);
    // Ketuk ganda / layar lain: tahap yang sama atau sebelumnya = tanpa perubahan
    $advance->handle($this->pos->supervisor, $order, FulfillmentStatus::Ready);
    $advance->handle($this->pos->supervisor, $order, FulfillmentStatus::Preparing);
    $order->refresh();
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::Ready)
        ->and($order->prepared_at->format('H:i'))->toBe('05:03')->and($order->ready_at->format('H:i'))->toBe('05:08')
        ->and($order->fulfillment_updated_by)->toBe($this->pos->cashier->id);

    Carbon::setTestNow('2026-10-08 05:12:00');
    $advance->handle($this->pos->supervisor, $order, FulfillmentStatus::Served);
    expect($order->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Served)
        ->and($order->served_by)->toBe($this->pos->supervisor->id)->and($order->fulfillment_auto)->toBeFalse();

    // Lompat Antre → Diantar: tahap yang dilewati ikut diberi waktu
    $jump = $advance->handle($this->pos->cashier, paidTableOrder($this, 'Budi'), FulfillmentStatus::Served);
    expect($jump->prepared_at)->not->toBeNull()->and($jump->ready_at)->not->toBeNull()->and($jump->served_at)->not->toBeNull();

    expect(fn () => $advance->handle($this->pos->cashier, $order, FulfillmentStatus::Queued))
        ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::ValidationError->value));
});

it('ditolak: belum lunas / void (409), outlet lain (404), izin order.fulfill dicabut owner (403)', function () {
    $advance = app(AdvanceOrderFulfillment::class);
    $unpaid = app(CheckoutSelfOrder::class)->handle(SelfOrderContext::resolve($this->table->qr_token), [
        'id' => uuid(), 'customer_name' => 'X', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], null, '10.0.0.1')['order'];
    expect(fn () => $advance->handle($this->pos->cashier, $unpaid, FulfillmentStatus::Preparing))
        ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::OrderAlreadyClosed->value));

    $order = paidTableOrder($this);
    $stranger = User::factory()->cashier()->forOutlet(Outlet::factory()->create(['tenant_id' => $this->pos->tenantId]))->create();
    expect(fn () => $advance->handle($stranger, $order, FulfillmentStatus::Preparing))
        ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::NotFound->value));

    // K9: bawaan semua role; owner bisa mencabut dari kasir
    expect($this->pos->cashier->can('order.fulfill'))->toBeTrue();
    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Cashier, array_values(array_diff(UserRole::Cashier->grantedPermissions(), ['order.fulfill'])));
    expect(fn () => $advance->handle($this->pos->cashier->fresh(), $order, FulfillmentStatus::Preparing))
        ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::Forbidden->value));
});

it('Urungkan: hanya pelaku yang sama dalam 10 detik, satu langkah', function () {
    $order = paidTableOrder($this);
    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $order, FulfillmentStatus::Preparing);

    expect(fn () => app(UndoOrderFulfillment::class)->handle($this->pos->supervisor, $order))->toThrow(BusinessException::class);
    $undone = app(UndoOrderFulfillment::class)->handle($this->pos->cashier, $order);
    expect($undone->fulfillment_status)->toBe(FulfillmentStatus::Queued)->and($undone->prepared_at)->toBeNull();
    expect(fn () => app(UndoOrderFulfillment::class)->handle($this->pos->cashier, $order))->toThrow(BusinessException::class);

    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $order, FulfillmentStatus::Ready);
    Carbon::setTestNow('2026-10-08 05:00:11');
    expect(fn () => app(UndoOrderFulfillment::class)->handle($this->pos->cashier, $order))->toThrow(BusinessException::class, 'sudah tidak bisa diurungkan');
});

it('selesai otomatis setelah batas outlet: hanya pesanan lunas yang belum diantar; aman diulang; tercatat', function () {
    $waiting = paidTableOrder($this, 'Andi');
    $served = app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, paidTableOrder($this, 'Budi'), FulfillmentStatus::Served);
    $unpaid = app(CheckoutSelfOrder::class)->handle(SelfOrderContext::resolve($this->table->qr_token), [
        'id' => uuid(), 'customer_name' => 'C', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], null, '10.0.0.2')['order'];

    Carbon::setTestNow('2026-10-08 05:59:00');
    expect(app(AutoCompleteFulfillment::class)->handle())->toBe(0);

    Carbon::setTestNow('2026-10-08 06:01:00');
    $this->artisan('pos:auto-complete-fulfillment')->expectsOutput('1 pesanan meja selesai otomatis.')->assertSuccessful();
    $this->artisan('pos:auto-complete-fulfillment')->expectsOutput('0 pesanan meja selesai otomatis.')->assertSuccessful();
    TenantContext::set($this->pos->tenantId);

    expect($waiting->refresh()->fulfillment_status)->toBe(FulfillmentStatus::AutoCompleted)
        ->and($waiting->fulfillment_auto)->toBeTrue()->and($waiting->served_by)->toBeNull()
        ->and($served->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Served)
        ->and($unpaid->refresh()->fulfillment_status)->toBeNull()
        ->and(ActivityLog::query()->where('log_name', 'fulfillment')->where('subject_id', $waiting->id)->where('event', 'auto_completed')->exists())->toBeTrue();

    // Setelan outlet 30 menit
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'auto_complete_minutes' => 30]);
    $next = paidTableOrder($this, 'Dewi');
    Carbon::setTestNow('2026-10-08 06:32:00');
    expect(app(AutoCompleteFulfillment::class)->handle())->toBe(1)->and($next->refresh()->fulfillment_auto)->toBeTrue();

    expect(fn () => app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'auto_complete_minutes' => 10]))
        ->toThrow(ValidationException::class);
});

it('kasir web /cashier/queue: tiga kolom, langkah + Urungkan, filter area, outlet sendiri; tanpa shift tetap bisa', function () {
    $order = paidTableOrder($this, 'Andi');
    $other = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 9', 'area' => 'Dalam']);
    paidTableOrder($this, 'Budi', $other->id);

    $page = queuePage($this, $this->pos->cashier)
        ->assertSee('Antrean pesanan')->assertSee('Antre & disiapkan')->assertSee('Siap diantar')->assertSee('Diantar (1 jam terakhir)')
        ->assertSee('Andi')->assertSee('Budi')->assertSee('Siapkan')
        ->set('area', 'Teras')->assertSee('Andi')->assertDontSee('Budi')
        ->call('advance', $order->id)
        ->assertDispatched('cashier-toast', message: 'Meja 4 · Disiapkan', tone: 'success', undo: $order->id);
    expect($order->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Preparing);

    $page->dispatch('fulfillment-undo', orderId: $order->id)->assertDispatched('cashier-toast', message: 'Langkah diurungkan', tone: 'info');
    expect($order->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Queued);

    $page->call('advance', $order->id)->call('advance', $order->id)->call('advance', $order->id);
    expect($order->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Served);
    $page->assertSee('Diantar 12.00');

    $this->get(route('cashier.queue'))->assertOk()->assertSee('Antrean');
});

it('kasir web: tanpa order.fulfill halaman antrean 403; menu Pesanan meja menampilkan tombol langkah yang sama', function () {
    $order = paidTableOrder($this);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '0']);
    Auth::shouldUse(WebCashier::GUARD);

    Livewire::test(SelfOrders::class)->assertSee('Sudah dibayar')->assertSee('Siapkan')
        ->call('advance', $order->id);
    expect($order->refresh()->fulfillment_status)->toBe(FulfillmentStatus::Preparing);

    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Cashier, array_values(array_diff(UserRole::Cashier->grantedPermissions(), ['order.fulfill'])));
    $this->get(route('cashier.queue'))->assertForbidden();
});

it('API: POST /orders/{id}/fulfillment maju & idempotent; daftar antrean per outlet; izin & tenant', function () {
    $order = paidTableOrder($this);
    $url = "/api/v1/orders/{$order->id}/fulfillment";

    freshAuth();
    $this->withToken($this->pos->token)->postJson($url, ['status' => 'preparing'], apiHeaders())->assertOk()
        ->assertJsonPath('data.fulfillment_status', 'preparing')->assertJsonPath('data.paid_at', '2026-10-08T05:00:00Z');
    freshAuth();
    $this->withToken($this->pos->token)->postJson($url, ['status' => 'preparing'], apiHeaders())->assertOk()->assertJsonPath('data.fulfillment_status', 'preparing');
    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson($url, ['status' => 'queued'], apiHeaders()), 'VALIDATION_ERROR', 422);

    freshAuth();
    $this->withToken($this->pos->token)->getJson('/api/v1/orders?fulfillment=queued,preparing', apiHeaders())->assertOk()
        ->assertJsonPath('data.0.id', $order->id)->assertJsonPath('data.0.fulfillment_status', 'preparing');

    $other = posSetup();
    freshAuth();
    assertApiError($this->withToken($other->token)->postJson($url, ['status' => 'ready'], apiHeaders()), 'NOT_FOUND', 404);

    TenantContext::set($this->pos->tenantId);
    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Cashier, array_values(array_diff(UserRole::Cashier->grantedPermissions(), ['order.fulfill'])));
    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson($url, ['status' => 'ready'], apiHeaders()), 'FORBIDDEN', 403);
});

it('HP: lini waktu per tahap dengan waktu, lalu struk digital & Pesan lagi', function () {
    $order = paidTableOrder($this);
    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $order, FulfillmentStatus::Preparing);
    $selfOrderId = SelfOrder::query()->where('order_id', $order->id)->value('id');
    session()->put('self_orders.'.$this->table->id, [$selfOrderId]);
    SelfOrderContext::set(SelfOrderContext::resolve($this->table->qr_token));

    $steps = OrderPage::timeline($order->refresh());
    expect(array_column($steps, 'state'))->toBe(['done', 'done', 'current', 'todo', 'todo'])
        ->and(array_column($steps, 'label'))->toBe(['Menunggu bayar', 'Lunas', 'Disiapkan', 'Siap diantar', 'Diantar ke meja']);

    Livewire::test(OrderPage::class)->assertSet('view', 'status')
        ->assertSee('Disiapkan')->assertSee('Pesananmu sedang disiapkan')
        ->assertSee('Struk digital')->assertSee('Croissant')->assertSee('Tunai')->assertSee('Pesan lagi');

    app(AdvanceOrderFulfillment::class)->handle($this->pos->cashier, $order, FulfillmentStatus::Served);
    expect(array_column(OrderPage::timeline($order->refresh()), 'state'))->toBe(['done', 'done', 'done', 'done', 'done']);
});
