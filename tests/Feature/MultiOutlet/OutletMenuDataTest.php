<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\OutletOption;
use App\Models\OutletPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;

/*
 * Data menu per outlet (ADR 0011, SPEC Q42–Q45): produk dijual, opsi habis, dan metode bayar per outlet.
 * Tanpa baris = dijual / tersedia / aktif, sama seperti stok outlet (ADR 0010).
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $this->outletB = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create(['code' => 'BDG01']));
});

/** Jalankan di konteks tenant posSetup(). */
function inPos(object $test, Closure $callback): mixed
{
    return TenantContext::run($test->pos->tenantId, $callback);
}

describe('produk dijual per outlet (Q42)', function () {
    it('produk tanpa baris outlet dianggap dijual', function () {
        inPos($this, function (): void {
            $product = Product::query()->atOutlet($this->outletB->id)->findOrFail($this->pos->coffee->id);

            expect($product->is_listed)->toBeTrue()
                ->and(Product::query()->listedAt($this->outletB->id)->pluck('id'))->toContain($this->pos->coffee->id);
        });
    });

    it('produk yang tidak dijual di outlet B tetap dijual di outlet A', function () {
        inPos($this, function (): void {
            $stock = ProductStock::for($this->pos->coffee->id, $this->outletB->id);
            $stock->update(['is_listed' => false]);

            expect(Product::query()->listedAt($this->outletB->id)->pluck('id'))->not->toContain($this->pos->coffee->id)
                ->and(Product::query()->listedAt($this->pos->outlet->id)->pluck('id'))->toContain($this->pos->coffee->id)
                ->and(Product::query()->atOutlet($this->outletB->id)->findOrFail($this->pos->coffee->id)->is_listed)->toBeFalse()
                ->and($this->pos->coffee->fresh()->loadOutletState($this->outletB->id)->is_listed)->toBeFalse();
        });
    });
});

describe('opsi habis per outlet (Q43)', function () {
    it('opsi habis di outlet B tetap tersedia di outlet A', function () {
        inPos($this, function (): void {
            OutletOption::query()->create(['outlet_id' => $this->outletB->id, 'option_id' => $this->pos->large->id, 'is_available' => false]);

            expect(OutletOption::unavailableIds($this->outletB->id))->toBe([$this->pos->large->id])
                ->and(OutletOption::unavailableIds($this->pos->outlet->id))->toBe([]);
        });
    });

    it('baris ganda untuk opsi & outlet yang sama ditolak database', function () {
        inPos($this, function (): void {
            $row = ['outlet_id' => $this->outletB->id, 'option_id' => $this->pos->large->id];
            OutletOption::query()->create($row);

            expect(fn () => OutletOption::query()->create($row))->toThrow(QueryException::class);
        });
    });
});

describe('metode bayar per outlet (Q44)', function () {
    it('metode aktif bila aktif di bisnis dan tidak dinonaktifkan di outlet', function () {
        inPos($this, function (): void {
            $qris = $this->pos->methods['qris'];
            OutletPaymentMethod::query()->create(['outlet_id' => $this->outletB->id, 'payment_method_id' => $qris->id, 'is_active' => false]);
            $this->pos->methods['debit']->update(['is_active' => false]);

            $activeB = PaymentMethod::query()->activeAt($this->outletB->id)->pluck('id');
            $activeA = PaymentMethod::query()->activeAt($this->pos->outlet->id)->pluck('id');

            expect($activeB)->not->toContain($qris->id)->not->toContain($this->pos->methods['debit']->id)
                ->and($activeA)->toContain($qris->id)->not->toContain($this->pos->methods['debit']->id);
        });
    });

    it('baris ganda untuk metode & outlet yang sama ditolak database', function () {
        inPos($this, function (): void {
            $row = ['outlet_id' => $this->outletB->id, 'payment_method_id' => $this->pos->methods['cash']->id];
            OutletPaymentMethod::query()->create($row);

            expect(fn () => OutletPaymentMethod::query()->create($row))->toThrow(QueryException::class);
        });
    });
});

describe('isolasi tenant', function () {
    it('baris opsi & metode bayar per outlet terisi tenant otomatis dan tidak terlihat tenant lain', function () {
        inPos($this, function (): void {
            OutletOption::query()->create(['outlet_id' => $this->outletB->id, 'option_id' => $this->pos->large->id, 'is_available' => false]);
            OutletPaymentMethod::query()->create(['outlet_id' => $this->outletB->id, 'payment_method_id' => $this->pos->methods['qris']->id, 'is_active' => false]);
        });

        $other = posSetup(openShift: false);

        TenantContext::run($other->tenantId, function () use ($other): void {
            expect(OutletOption::query()->count())->toBe(0)
                ->and(OutletPaymentMethod::query()->count())->toBe(0)
                ->and(OutletOption::unavailableIds($this->outletB->id))->toBe([])
                ->and(PaymentMethod::query()->activeAt($other->outlet->id)->count())->toBe(5);
        });

        expect(OutletOption::allTenants()->value('tenant_id'))->toBe($this->pos->tenantId)
            ->and(OutletPaymentMethod::allTenants()->value('tenant_id'))->toBe($this->pos->tenantId);
    });
});
