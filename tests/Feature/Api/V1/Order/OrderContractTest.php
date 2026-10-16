<?php

declare(strict_types=1);

use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Enums\TenantStatus;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Kontrak endpoint order, member, voucher, dan self-order (fase 2 coverage): permission, isolasi tenant/outlet,
 * dan request ganda. Semua role tenant memegang order.create, order.reprint, dan self_order.handle
 * (UserRole::permissions()), jadi "permission ditolak" diuji lewat:
 *   - device token tanpa user (middleware token.user) → 401 UNAUTHENTICATED;
 *   - tenant hanya-baca (ADR 0009): request yang mengubah data → 403 SUBSCRIPTION_EXPIRED (EnsureTenantActive),
 *     GET dengan permission tulis (order.create, self_order.handle) → 403 FORBIDDEN (Gate::before);
 *   - struk: visibilitas order (OrderPolicy::reprint → VisibleOrders) → 403 FORBIDDEN.
 */
beforeEach(function () {
    $this->pos = posSetup();
});

function ocApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Open bill 1 Croissant lewat API di $pos (default tenant utama). */
function ocOpenBill(object $test, ?stdClass $pos = null, ?string $id = null): string
{
    $pos ??= $test->pos;
    $id ??= uuid();
    ocApi($test, 'PUT', "/orders/{$id}", ocBillBody($pos), $pos->token)->assertSuccessful();

    return $id;
}

function ocBillBody(stdClass $pos): array
{
    return ['order_type' => 'dine_in', 'table_label' => 'Meja 3', 'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]]];
}

/** Checkout tunai 1 Croissant lewat API; mengembalikan respons data order. */
function ocCheckout(object $test, stdClass $pos, ?string $token = null): array
{
    return ocApi($test, 'POST', '/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 30000]],
    ], $token ?? $pos->token)->assertCreated()->json('data');
}

function ocMakeReadOnly(stdClass $pos): void
{
    Tenant::query()->whereKey($pos->tenantId)->update(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->subDay()]);
}

/** Pesanan meja pending di outlet $pos (self-order aktif, meja "Meja 5"). */
function ocSelfOrder(stdClass $pos): SelfOrder
{
    return TenantContext::run($pos->tenantId, function () use ($pos): SelfOrder {
        app(UpdateSelfOrderSettings::class)->handle($pos->outlet, ['enabled' => true]);
        $table = app(SaveDiningTable::class)->handle(null, $pos->outlet, ['name' => 'Meja '.random_int(10, 99999)]);
        RateLimiter::clear('self-order-ip:127.0.0.1');

        return app(SubmitSelfOrder::class)->handle(SelfOrderContext::resolve($table->qr_token), [
            'id' => uuid(), 'customer_name' => 'Andi',
            'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        ], null, '127.0.0.1')['self_order'];
    });
}

describe('permission (semua role berizin → device token & tenant hanya-baca)', function () {
    /**
     * Endpoint tulis: [method, uri builder]. Target memakai data nyata agar penolakan pasti dari lapisan akses,
     * bukan route binding.
     */
    $writes = [
        'POST /orders/{id}/payments' => ['POST', fn (object $t) => '/orders/'.ocOpenBill($t).'/payments', fn (object $t) => ['payments' => [['id' => uuid(), 'payment_method_id' => $t->pos->methods['cash']->id, 'amount' => 30000]]]],
        'POST /orders/{id}/split' => ['POST', fn (object $t) => '/orders/'.ocOpenBill($t).'/split', fn () => ['new_order_id' => uuid(), 'items' => [['order_item_id' => uuid(), 'qty' => 1]]]],
        'POST /orders/{id}/merge' => ['POST', fn (object $t) => '/orders/'.ocOpenBill($t).'/merge', fn (object $t) => ['source_order_id' => ocOpenBill($t)]],
        'PUT /orders/{id}' => ['PUT', fn () => '/orders/'.uuid(), fn (object $t) => ocBillBody($t->pos)],
        'POST /members/{id}/receivable-payments' => ['POST', fn () => '/members/'.uuid().'/receivable-payments', fn (object $t) => ['id' => uuid(), 'amount' => '1000', 'payment_method_id' => $t->pos->methods['cash']->id]],
        'POST /orders/quote' => ['POST', fn () => '/orders/quote', fn (object $t) => ['items' => [['product_id' => $t->pos->croissant->id, 'qty' => 1]]]],
    ];

    it('device token tanpa user ditolak 401 UNAUTHENTICATED', function (string $method, Closure $uri, Closure $body) {
        $uri = $uri($this);
        $body = $body($this);
        $before = Order::allTenants()->count();

        assertApiError(ocApi($this, $method, $uri, $body, deviceToken($this->pos->device)), 'UNAUTHENTICATED', 401);
        expect(Order::allTenants()->count())->toBe($before);
    })->with($writes);

    it('tenant hanya-baca: request yang mengubah data ditolak 403 SUBSCRIPTION_EXPIRED', function (string $method, Closure $uri, Closure $body) {
        $uri = $uri($this);
        $body = $body($this);
        $before = Order::allTenants()->count();
        ocMakeReadOnly($this->pos);

        assertApiError(ocApi($this, $method, $uri, $body), 'SUBSCRIPTION_EXPIRED', 403);
        expect(Order::allTenants()->count())->toBe($before)
            ->and(Order::allTenants()->where('status', '!=', OrderStatus::Open)->count())->toBe(0);
    })->with($writes);

    it('GET /vouchers/check: device token 401, tenant hanya-baca 403 FORBIDDEN (order.create bukan permission baca)', function () {
        TenantContext::run($this->pos->tenantId, fn () => app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]));

        assertApiError(ocApi($this, 'GET', '/vouchers/check?code=HEMAT5', token: deviceToken($this->pos->device)), 'UNAUTHENTICATED', 401);

        ocMakeReadOnly($this->pos);
        assertApiError(ocApi($this, 'GET', '/vouchers/check?code=HEMAT5'), 'FORBIDDEN', 403);
    });
});

describe('POST /orders/{id}/merge — isolasi tenant', function () {
    it('source_order_id milik tenant lain → 404; bill tenant lain tidak berubah', function () {
        $target = ocOpenBill($this);
        $other = posSetup();
        $foreign = ocOpenBill($this, $other);

        assertApiError(ocApi($this, 'POST', "/orders/{$target}/merge", ['source_order_id' => $foreign]), 'NOT_FOUND', 404);

        expect(Order::allTenants()->findOrFail($foreign)->status)->toBe(OrderStatus::Open)
            ->and(Order::allTenants()->findOrFail($target)->items()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('target milik tenant lain → 404; bill sumber tetap open', function () {
        $source = ocOpenBill($this);
        $other = posSetup();
        $foreign = ocOpenBill($this, $other);

        assertApiError(ocApi($this, 'POST', "/orders/{$foreign}/merge", ['source_order_id' => $source]), 'NOT_FOUND', 404);

        expect(Order::allTenants()->findOrFail($source)->status)->toBe(OrderStatus::Open)
            ->and(Order::allTenants()->findOrFail($foreign)->items()->withoutGlobalScopes()->count())->toBe(1);
    });
});

describe('GET /refundable-orders/{number} — isolasi', function () {
    it('nomor order yang hanya ada di tenant lain (format sama) → 404', function () {
        $other = posSetup();
        $number = ocCheckout($this, $other)['order_number'];
        expect($number)->toStartWith('JKT01-');

        assertApiError(ocApi($this, 'GET', "/refundable-orders/{$number}"), 'NOT_FOUND', 404);
        // Kontrol: tenant pemilik menemukannya
        ocApi($this, 'GET', "/refundable-orders/{$number}", token: $other->token)->assertOk()->assertJsonPath('data.order_number', $number);
    });

    it('nomor order outlet lain di tenant yang sama → 404', function () {
        $number = ocCheckout($this, $this->pos)['order_number'];
        $outlet2 = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create());
        $device2 = Device::factory()->forOutlet($outlet2)->create();
        $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

        assertApiError(ocApi($this, 'GET', "/refundable-orders/{$number}", token: userToken($owner, $device2)), 'NOT_FOUND', 404);
        ocApi($this, 'GET', "/refundable-orders/{$number}", token: userToken($owner, $this->pos->device))->assertOk();
    });
});

describe('POST /orders/{id}/payments — isolasi tenant', function () {
    it('open bill tenant lain → 404 tanpa pembayaran tercatat', function () {
        $other = posSetup();
        $foreign = ocOpenBill($this, $other);

        assertApiError(ocApi($this, 'POST', "/orders/{$foreign}/payments", ['payments' => [
            ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 30000],
        ]]), 'NOT_FOUND', 404);

        $order = Order::allTenants()->findOrFail($foreign);
        expect($order->status)->toBe(OrderStatus::Open)->and($order->paid_total)->toBe('0.00');
    });
});

describe('PUT /orders/{id} — request ganda', function () {
    // Kontrak docs/api/order.md: upsert, 201 (dibuat) / 200 (diubah) tanpa meta.idempotent_replay
    it('PUT dua kali dengan id & isi sama → satu order; kedua 200 tanpa flag replay, nomor order tetap', function () {
        $id = uuid();
        $first = ocApi($this, 'PUT', "/orders/{$id}", ocBillBody($this->pos))->assertCreated();
        $second = ocApi($this, 'PUT', "/orders/{$id}", ocBillBody($this->pos))->assertOk()
            ->assertJsonPath('message', 'Open bill berhasil diperbarui')
            ->assertJsonMissingPath('meta.idempotent_replay');

        expect(Order::allTenants()->count())->toBe(1)
            ->and($second->json('data.order_number'))->toBe($first->json('data.order_number'))
            ->and(Order::allTenants()->findOrFail($id)->items()->withoutGlobalScopes()->count())->toBe(1);
    });
});

describe('GET /orders/{id}/receipt — OrderPolicy::reprint', function () {
    it('kasir tidak bisa mencetak struk order kasir lain di shift lain → 403 FORBIDDEN', function () {
        $otherCashier = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
        $device2 = Device::factory()->forOutlet($this->pos->outlet)->create();
        TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($device2, $otherCashier)->create());
        $otherToken = userToken($otherCashier, $device2);
        $orderId = ocCheckout($this, $this->pos, $otherToken)['id'];

        assertApiError(ocApi($this, 'GET', "/orders/{$orderId}/receipt"), 'FORBIDDEN', 403);
        ocApi($this, 'GET', "/orders/{$orderId}/receipt", token: $otherToken)->assertOk();
    });
});

describe('GET /vouchers/check', function () {
    beforeEach(function () {
        TenantContext::run($this->pos->tenantId, function (): void {
            app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
            app(SaveVoucher::class)->handle(null, ['code' => 'hemat5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);
        });
    });

    it('tanpa code → 422 VALIDATION_ERROR', function () {
        assertApiError(ocApi($this, 'GET', '/vouchers/check'), 'VALIDATION_ERROR', 422);
    });

    it('kode voucher milik tenant lain → 422 VOUCHER_INVALID', function () {
        $other = posSetup();
        TenantContext::run($other->tenantId, function () use ($other): void {
            app(UpdateMemberProgram::class)->handle(true, [$other->outlet->id]);
            app(SaveVoucher::class)->handle(null, ['code' => 'lain10', 'name' => 'Lain', 'type' => 'fixed', 'value' => '10000']);
        });

        assertApiError(ocApi($this, 'GET', '/vouchers/check?code=LAIN10'), 'VOUCHER_INVALID', 422);
        // Kontrol: kode tenant sendiri sah
        ocApi($this, 'GET', '/vouchers/check?code=HEMAT5')->assertOk();
    });
});

describe('self-orders', function () {
    it('device token ditolak 401 di daftar, terima, dan tolak', function () {
        $selfOrder = ocSelfOrder($this->pos);
        $token = deviceToken($this->pos->device);

        assertApiError(ocApi($this, 'GET', '/self-orders', token: $token), 'UNAUTHENTICATED', 401);
        assertApiError(ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/accept", token: $token), 'UNAUTHENTICATED', 401);
        assertApiError(ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/reject", ['reason' => 'Habis'], $token), 'UNAUTHENTICATED', 401);
        expect($selfOrder->refresh()->status)->toBe(SelfOrderStatus::Pending);
    });

    it('tenant hanya-baca: daftar 403 FORBIDDEN (self_order.handle bukan permission baca); terima & tolak 403 SUBSCRIPTION_EXPIRED', function () {
        $selfOrder = ocSelfOrder($this->pos);
        ocMakeReadOnly($this->pos);

        assertApiError(ocApi($this, 'GET', '/self-orders'), 'FORBIDDEN', 403);
        assertApiError(ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/accept"), 'SUBSCRIPTION_EXPIRED', 403);
        assertApiError(ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/reject", ['reason' => 'Habis']), 'SUBSCRIPTION_EXPIRED', 403);
        expect(SelfOrder::allTenants()->findOrFail($selfOrder->id)->status)->toBe(SelfOrderStatus::Pending);
    });

    it('tolak pesanan tenant lain → 404; pesanan tetap pending', function () {
        $selfOrder = ocSelfOrder($this->pos);
        $other = posSetup();

        assertApiError(ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/reject", ['reason' => 'Habis'], $other->token), 'NOT_FOUND', 404);
        expect(SelfOrder::allTenants()->findOrFail($selfOrder->id)->status)->toBe(SelfOrderStatus::Pending);
    });

    it('tolak dua kali → kedua 409 ORDER_NOT_PENDING; alasan pertama tetap', function () {
        $selfOrder = ocSelfOrder($this->pos);

        ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/reject", ['reason' => 'Habis'])->assertOk()->assertJsonPath('data.status', 'rejected');
        assertApiError(ocApi($this, 'POST', "/self-orders/{$selfOrder->id}/reject", ['reason' => 'Tutup']), 'ORDER_NOT_PENDING', 409);

        $fresh = SelfOrder::allTenants()->findOrFail($selfOrder->id);
        expect($fresh->status)->toBe(SelfOrderStatus::Rejected)->and($fresh->reject_reason)->toBe('Habis');
    });
});
