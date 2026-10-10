<?php

declare(strict_types=1);

use App\Actions\Outlet\SetOutletActive;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * Multi-outlet di API (ADR 0010, SPEC Q36–Q41): outlet A = posSetup() (JKT01), outlet B = BDG01
 * di bisnis yang sama dengan perangkat, kasir, dan shift sendiri.
 */
beforeEach(function () {
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->outletB = Outlet::factory()->create(['code' => 'BDG01', 'name' => 'Outlet Bandung']);
        $this->deviceB = Device::factory()->forOutlet($this->outletB)->create(['device_uid' => 'kasir-bdg']);
        $this->cashierB = User::factory()->cashier()->forOutlet($this->outletB)->create(['name' => 'Rina']);
        Shift::factory()->forDevice($this->deviceB, $this->cashierB)->create();
        $this->owner = User::factory()->owner()->create(['name' => 'Owner']);
        $this->managerA = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
    });

    $this->tokenB = userToken($this->cashierB, $this->deviceB);
});

/** Checkout 1 croissant tunai dengan token kasir tertentu. */
function sellCroissant(object $test, string $token): TestResponse
{
    freshAuth();

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => uuid(),
        'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 100000, 'tendered' => 100000]],
    ], apiHeaders());
}

describe('akses outlet', function () {
    it('kasir outlet A ditolak saat login di perangkat outlet B', function () {
        assertApiError(
            $this->withToken(userToken($this->pos->cashier, $this->deviceB))->getJson('/api/v1/catalog', apiHeaders()),
            'FORBIDDEN',
            403,
        );
    });

    it('layar PIN perangkat B hanya menampilkan karyawan outlet B dan owner', function () {
        $names = $this->withToken(deviceToken($this->deviceB))
            ->getJson('/api/v1/devices/kasir-bdg/cashiers', apiHeaders())
            ->assertOk()
            ->json('data.*.name');

        expect($names)->toContain('Rina', 'Owner')->not->toContain('Budi', 'Sari');
    });

    it('GET /outlets hanya outlet yang dipegang; owner semua outlet', function () {
        $codes = fn (User $user): array => $this->withToken(userToken($user))->getJson('/api/v1/outlets', apiHeaders())
            ->assertOk()->json('data.*.code');

        expect($codes($this->managerA))->toBe(['JKT01']);
        freshAuth();
        expect($codes($this->owner))->toEqualCanonicalizing(['JKT01', 'BDG01']);
    });

    it('order outlet B tidak terlihat manager outlet A (404), owner melihat keduanya', function () {
        $orderB = sellCroissant($this, $this->tokenB)->assertCreated()->json('data.id');
        $orderA = sellCroissant($this, $this->pos->token)->assertCreated()->json('data.id');

        freshAuth();
        $managerToken = userToken($this->managerA);
        assertApiError($this->withToken($managerToken)->getJson("/api/v1/orders/{$orderB}", apiHeaders()), 'NOT_FOUND', 404);

        freshAuth();
        expect($this->withToken($managerToken)->getJson('/api/v1/orders', apiHeaders())->json('data.*.id'))
            ->toContain($orderA)->not->toContain($orderB);

        freshAuth();
        expect($this->withToken(userToken($this->owner))->getJson('/api/v1/orders', apiHeaders())->json('data.*.id'))
            ->toContain($orderA, $orderB);
    });

    it('outlet nonaktif: perangkat & kasirnya ditolak', function () {
        TenantContext::run($this->pos->tenantId, fn () => app(SetOutletActive::class)->handle($this->outletB, false));

        assertApiError($this->withToken($this->tokenB)->getJson('/api/v1/catalog', apiHeaders()), 'FORBIDDEN', 403);
        freshAuth();
        assertApiError(
            $this->withToken(deviceToken($this->deviceB))->getJson('/api/v1/devices/kasir-bdg/cashiers', apiHeaders()),
            'FORBIDDEN',
            403,
        );
    });
});

describe('stok & ketersediaan per outlet', function () {
    it('checkout di outlet B hanya memotong stok outlet B', function () {
        sellCroissant($this, $this->tokenB)->assertCreated();

        expect(stockOf($this->pos->croissant, $this->outletB->id)->stock_qty)->toBe(-1)
            ->and(stockOf($this->pos->croissant, $this->pos->outlet->id)->stock_qty)->toBe(10)
            ->and(StockMovement::allTenants()->sole()->outlet_id)->toBe($this->outletB->id);
    });

    it('tandai habis di outlet A tidak mempengaruhi katalog outlet B', function () {
        $ownerOnA = userToken($this->owner, $this->pos->device);
        $this->withToken($ownerOnA)
            ->patchJson("/api/v1/products/{$this->pos->croissant->id}/availability", ['is_available' => false], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.is_available', false);

        $availability = function (string $token): ?bool {
            freshAuth();
            $products = collect($this->withToken($token)->getJson('/api/v1/catalog', apiHeaders())->assertOk()->json('data.products'));

            return $products->firstWhere('id', $this->pos->croissant->id)['is_available'] ?? null;
        };

        expect($availability($this->pos->token))->toBeFalse()
            ->and($availability($this->tokenB))->toBeTrue();

        // Kasir A tidak bisa menjual, kasir B tetap bisa
        assertApiError(sellCroissant($this, $this->pos->token), 'VALIDATION_ERROR', 422);
        sellCroissant($this, $this->tokenB)->assertCreated();
    });

    it('penyesuaian stok di perangkat B mencatat outlet B', function () {
        $managerB = TenantContext::run($this->pos->tenantId, fn () => User::factory()->manager()->forOutlet($this->outletB)->create());

        $this->withToken(userToken($managerB, $this->deviceB))
            ->postJson("/api/v1/products/{$this->pos->croissant->id}/stock", ['id' => uuid(), 'qty_change' => 5, 'reason' => 'Barang masuk'], apiHeaders())
            ->assertCreated()
            ->assertJsonPath('data.product.stock_qty', 5);

        expect(stockOf($this->pos->croissant, $this->outletB->id)->stock_qty)->toBe(5)
            ->and(stockOf($this->pos->croissant, $this->pos->outlet->id)->stock_qty)->toBe(10);
    });
});

describe('laporan per outlet', function () {
    it('outlet_id membatasi angka laporan; outlet di luar penugasan → 404', function () {
        sellCroissant($this, $this->tokenB)->assertCreated();
        sellCroissant($this, $this->pos->token)->assertCreated();
        sellCroissant($this, $this->pos->token)->assertCreated();

        $count = function (User $user, string $outlet): TestResponse {
            freshAuth();

            return $this->withToken(userToken($user))->getJson("/api/v1/reports/summary?outlet_id={$outlet}", apiHeaders());
        };

        expect($count($this->owner, $this->outletB->id)->assertOk()->json('data.order_count'))->toBe(1)
            ->and($count($this->owner, 'all')->json('data.order_count'))->toBe(3)
            ->and($count($this->managerA, 'all')->json('data.order_count'))->toBe(2);

        assertApiError($count($this->managerA, $this->outletB->id), 'NOT_FOUND', 404);
    });
});

describe('penugasan karyawan', function () {
    it('owner menugaskan kasir ke beberapa outlet; request ganda tetap satu karyawan', function () {
        $body = ['id' => uuid(), 'name' => 'Dewi', 'role' => 'cashier', 'pin' => '481920', 'username' => 'dewi',
            'password' => 'rahasia123', 'outlet_ids' => [$this->pos->outlet->id, $this->outletB->id]];
        $token = userToken($this->owner);

        $this->withToken($token)->postJson('/api/v1/users', $body, apiHeaders())->assertCreated()
            ->assertJsonPath('data.all_outlets', false);
        freshAuth();
        $this->withToken($token)->postJson('/api/v1/users', $body, apiHeaders())->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $dewi = User::allTenants()->where('username', 'dewi')->sole();
        expect($dewi->outletIds())->toEqualCanonicalizing([$this->pos->outlet->id, $this->outletB->id]);
    });

    it('outlet milik bisnis lain atau daftar kosong ditolak', function () {
        $foreign = Outlet::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $token = userToken($this->owner);

        foreach ([[$foreign->id], []] as $outletIds) {
            freshAuth();
            assertApiError(
                $this->withToken($token)->postJson('/api/v1/users', ['name' => 'Eka', 'role' => 'cashier', 'pin' => '481920',
                    'username' => 'eka'.count($outletIds), 'password' => 'rahasia123', 'outlet_ids' => $outletIds], apiHeaders()),
                'VALIDATION_ERROR',
                422,
            );
        }

        expect(User::allTenants()->where('name', 'Eka')->exists())->toBeFalse();
    });

    it('memindahkan kasir ke outlet B: login di perangkat A langsung ditolak', function () {
        freshAuth();
        $this->withToken(userToken($this->owner))
            ->putJson("/api/v1/users/{$this->pos->cashier->id}", ['username' => 'budi', 'outlet_ids' => [$this->outletB->id]], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.outlet_ids', [$this->outletB->id]);

        freshAuth();
        assertApiError($this->withToken($this->pos->token)->getJson('/api/v1/catalog', apiHeaders()), 'FORBIDDEN', 403);
    });
});

it('order baru di outlet B memakai kode outlet B', function () {
    $id = sellCroissant($this, $this->tokenB)->assertCreated()->json('data.id');

    expect(Order::allTenants()->findOrFail($id)->order_number)->toStartWith('BDG01-');
});
