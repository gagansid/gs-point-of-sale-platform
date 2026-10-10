<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->outlet = Outlet::factory()->create();
    $this->manager = User::factory()->manager()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->token = userToken($this->manager);
    $this->product = TenantContext::run($this->outlet->tenant_id, fn () => Product::factory()->tracked(10)->create());
});

function adjust(array $override = []): array
{
    return ['id' => '0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b70', 'qty_change' => -3, 'reason' => 'Rusak', ...$override];
}

describe('penyesuaian stok', function () {
    it('mengurangi stok dan mencatat riwayat', function () {
        $this->withToken($this->token)->postJson("/api/v1/products/{$this->product->id}/stock", adjust(), apiHeaders())
            ->assertCreated()
            ->assertJsonPath('data.movement.qty_change', -3)
            ->assertJsonPath('data.movement.qty_after', 7)
            ->assertJsonPath('data.movement.type', 'adjustment')
            ->assertJsonPath('data.product.stock_qty', 7);

        expect(StockMovement::allTenants()->sole()->user_id)->toBe($this->manager->id);
    });

    it('stok boleh minus (penjualan tidak ditolak karena stok)', function () {
        $this->withToken($this->token)->postJson("/api/v1/products/{$this->product->id}/stock", adjust(['qty_change' => -15]), apiHeaders())
            ->assertJsonPath('data.product.stock_qty', -5);
    });

    it('request ganda dengan id yang sama tidak mengubah stok dua kali', function () {
        $this->withToken($this->token)->postJson("/api/v1/products/{$this->product->id}/stock", adjust(), apiHeaders())->assertCreated();
        freshAuth();
        $this->withToken($this->token)->postJson("/api/v1/products/{$this->product->id}/stock", adjust(), apiHeaders())
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.product.stock_qty', 7);

        expect(stockOf($this->product->id)->stock_qty)->toBe(7);
    });

    it('validasi: id wajib, qty tidak boleh 0, alasan wajib', function (array $override, string $field) {
        $response = $this->withToken($this->token)->postJson("/api/v1/products/{$this->product->id}/stock", adjust($override), apiHeaders());

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor($field, 'error.details');
    })->with([
        [['id' => null], 'id'],
        [['qty_change' => 0], 'qty_change'],
        [['qty_change' => 1.5], 'qty_change'],
        [['reason' => ''], 'reason'],
    ]);

    it('produk tanpa lacak stok ditolak', function () {
        $plain = TenantContext::run($this->outlet->tenant_id, fn () => Product::factory()->create());

        assertApiError($this->withToken($this->token)->postJson("/api/v1/products/{$plain->id}/stock", adjust(), apiHeaders()), 'VALIDATION_ERROR', 422);
    });

    it('supervisor tidak punya stock.adjust', function () {
        $supervisor = User::factory()->supervisor()->forOutlet($this->outlet)->create();

        assertApiError($this->withToken(userToken($supervisor))->postJson("/api/v1/products/{$this->product->id}/stock", adjust(), apiHeaders()), 'FORBIDDEN', 403);
    });

    it('isolasi: produk tenant lain → 404', function () {
        $foreignOutlet = Outlet::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $foreign = Product::factory()->tracked()->create(['tenant_id' => $foreignOutlet->tenant_id]);

        assertApiError($this->withToken($this->token)->postJson("/api/v1/products/{$foreign->id}/stock", adjust(), apiHeaders()), 'NOT_FOUND', 404);
        expect(stockOf($foreign->id)->stock_qty)->toBe(10);
    });
});

describe('tandai habis / tersedia', function () {
    it('supervisor menandai menu habis lalu tersedia lagi', function () {
        $supervisor = User::factory()->supervisor()->forOutlet($this->outlet)->create();
        $token = userToken($supervisor);

        $this->withToken($token)->patchJson("/api/v1/products/{$this->product->id}/availability", ['is_available' => false], apiHeaders())
            ->assertOk()
            ->assertJsonPath('message', 'Menu ditandai habis')
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.is_active', true);

        freshAuth();
        $this->withToken($token)->patchJson("/api/v1/products/{$this->product->id}/availability", ['is_available' => true], apiHeaders())
            ->assertJsonPath('data.is_available', true);
    });

    it('menandai dua kali tetap konsisten', function () {
        foreach ([1, 2] as $_) {
            freshAuth();
            $this->withToken($this->token)->patchJson("/api/v1/products/{$this->product->id}/availability", ['is_available' => false], apiHeaders())->assertOk();
        }

        expect(stockOf($this->product->id)->is_available)->toBeFalse();
    });

    it('validasi: is_available wajib boolean', function () {
        assertApiError($this->withToken($this->token)->patchJson("/api/v1/products/{$this->product->id}/availability", ['is_available' => 'mungkin'], apiHeaders()), 'VALIDATION_ERROR', 422);
    });

    it('kasir tidak boleh', function () {
        $cashier = User::factory()->cashier()->forOutlet($this->outlet)->create();

        assertApiError($this->withToken(userToken($cashier))->patchJson("/api/v1/products/{$this->product->id}/availability", ['is_available' => false], apiHeaders()), 'FORBIDDEN', 403);
    });

    it('isolasi: produk tenant lain → 404', function () {
        $foreign = Product::factory()->create();

        assertApiError($this->withToken($this->token)->patchJson("/api/v1/products/{$foreign->id}/availability", ['is_available' => false], apiHeaders()), 'NOT_FOUND', 404);
    });
});
