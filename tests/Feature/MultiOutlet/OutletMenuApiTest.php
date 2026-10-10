<?php

declare(strict_types=1);

use App\Actions\Payment\SetPaymentMethodAtOutlet;
use App\Actions\Product\SetOptionAvailability;
use App\Models\Category;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * Menu per outlet di API kasir (ADR 0011, SPEC Q42–Q44). Outlet A = posSetup() (JKT01, kasir Budi),
 * outlet B = BDG01 dengan perangkat, kasir, dan shift sendiri.
 */
beforeEach(function () {
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->outletB = Outlet::factory()->create(['code' => 'BDG01']);
        $this->deviceB = Device::factory()->forOutlet($this->outletB)->create(['device_uid' => 'kasir-bdg']);
        $this->cashierB = User::factory()->cashier()->forOutlet($this->outletB)->create(['name' => 'Rina']);
        Shift::factory()->forDevice($this->deviceB, $this->cashierB)->create();

        // Kopi tidak dijual di B; Large habis di B; QRIS nonaktif di B
        ProductStock::for($this->pos->coffee->id, $this->outletB->id)->update(['is_listed' => false]);
        app(SetOptionAvailability::class)->handle($this->pos->large, $this->outletB, false);
        app(SetPaymentMethodAtOutlet::class)->handle($this->pos->methods['qris'], $this->outletB, false);
    });

    $this->tokenB = userToken($this->cashierB, $this->deviceB);
});

function catalogFor(object $test, string $token): TestResponse
{
    freshAuth();

    return $test->withToken($token)->getJson('/api/v1/catalog', apiHeaders())->assertOk();
}

/** Checkout dengan token tertentu; default 1 croissant tunai. */
function checkoutWith(object $test, string $token, array $items = [], array $payments = [], ?string $id = null): TestResponse
{
    freshAuth();

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => $id ?? uuid(),
        'order_type' => 'takeaway',
        'items' => $items ?: [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'payments' => $payments ?: [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 100000, 'tendered' => 100000]],
    ], apiHeaders());
}

describe('GET /catalog', function () {
    it('outlet B tidak menerima produk yang tidak dijual dan metode bayar yang nonaktif', function () {
        $data = catalogFor($this, $this->tokenB)->json('data');

        expect(array_column($data['products'], 'id'))->toContain($this->pos->croissant->id)->not->toContain($this->pos->coffee->id)
            ->and(array_column($data['payment_methods'], 'id'))->not->toContain($this->pos->methods['qris']->id)
            ->and(array_column($data['payment_methods'], 'id'))->toContain($this->pos->methods['cash']->id);
    });

    it('opsi habis dikirim dengan is_available=false hanya di outlet B', function () {
        $options = fn (string $token): array => collect(catalogFor($this, $token)->json('data.option_groups'))
            ->flatMap(fn (array $group) => $group['options'])->pluck('is_available', 'id')->all();

        expect($options($this->tokenB)[$this->pos->large->id])->toBeFalse()
            ->and($options($this->tokenB)[$this->pos->regular->id])->toBeTrue()
            ->and($options($this->pos->token)[$this->pos->large->id])->toBeTrue();
    });

    it('kategori tanpa produk yang dijual di outlet tidak dikirim', function () {
        TenantContext::run($this->pos->tenantId, function (): void {
            $this->drinks = Category::factory()->create(['name' => 'Minuman']);
            $this->pos->coffee->update(['category_id' => $this->drinks->id]);
        });

        expect(array_column(catalogFor($this, $this->tokenB)->json('data.categories'), 'id'))->not->toContain($this->drinks->id)
            ->and(array_column(catalogFor($this, $this->pos->token)->json('data.categories'), 'id'))->toContain($this->drinks->id);
    });

    it('outlet A tetap menerima menu lengkap', function () {
        $data = catalogFor($this, $this->pos->token)->json('data');

        expect(array_column($data['products'], 'id'))->toContain($this->pos->coffee->id)
            ->and(array_column($data['payment_methods'], 'id'))->toContain($this->pos->methods['qris']->id);
    });
});

describe('POST /checkout', function () {
    it('produk yang tidak dijual di outlet ditolak VALIDATION_ERROR per item', function () {
        $response = checkoutWith($this, $this->tokenB, [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]]]);

        assertApiError($response, 'VALIDATION_ERROR', 422);
        expect($response->json('error.details'))->toHaveKey('items.0.product_id');
    });

    it('opsi habis di outlet ditolak VALIDATION_ERROR per item', function () {
        // Kopi dijual lagi di B, tapi Large masih habis di B
        TenantContext::run($this->pos->tenantId, fn () => ProductStock::for($this->pos->coffee->id, $this->outletB->id)->update(['is_listed' => true]));

        $response = checkoutWith($this, $this->tokenB, [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->large->id]]]);

        assertApiError($response, 'VALIDATION_ERROR', 422);
        expect($response->json('error.details')['items.0.option_ids'][0])->toContain('Large');
    });

    it('metode bayar nonaktif di outlet ditolak VALIDATION_ERROR per pembayaran', function () {
        $response = checkoutWith($this, $this->tokenB, payments: [
            ['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 100000, 'reference' => 'X1'],
        ]);

        assertApiError($response, 'VALIDATION_ERROR', 422);
        expect($response->json('error.details'))->toHaveKey('payments.0.payment_method_id');
    });

    it('di outlet A kopi Large dengan QRIS tetap bisa dibayar', function () {
        checkoutWith($this, $this->pos->token,
            [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->large->id]]],
            [
                ['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => '10000', 'reference' => 'X1'],
                ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000, 'tendered' => 100000],
            ],
        )->assertCreated();
    });

    it('request ganda di outlet B tetap menghasilkan satu order', function () {
        $id = uuid();
        $payments = [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000, 'tendered' => 100000]];

        checkoutWith($this, $this->tokenB, payments: $payments, id: $id)->assertCreated();
        checkoutWith($this, $this->tokenB, payments: $payments, id: $id)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

        expect(Order::allTenants()->whereKey($id)->count())->toBe(1);
    });
});
