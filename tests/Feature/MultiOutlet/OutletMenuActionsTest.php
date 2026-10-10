<?php

declare(strict_types=1);

use App\Actions\Outlet\CreateOutlet;
use App\Actions\Payment\SetPaymentMethodAtOutlet;
use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveProduct;
use App\Actions\Product\SetOptionAvailability;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\OutletOption;
use App\Models\OutletPaymentMethod;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Support\TenantContext;

/*
 * Action menu per outlet (ADR 0011, SPEC Q42–Q45). Outlet A = posSetup() (JKT01), outlet B = BDG01.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);
    $this->outletB = Outlet::factory()->create(['code' => 'BDG01']);
    $this->owner = User::factory()->owner()->create();
    $this->managerA = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
});

/** Data produk minimal dengan daftar outlet tempat produk dijual. */
function productData(array $overrides = []): ProductData
{
    return ProductData::fromArray(array_merge(['name' => 'Teh Tarik', 'price' => '15000'], $overrides));
}

function listedAt(Product $product, Outlet $outlet): bool
{
    return (bool) Product::query()->atOutlet($outlet->id)->findOrFail($product->id)->is_listed;
}

describe('produk dijual per outlet (Q42)', function () {
    it('produk baru tanpa pilihan outlet dijual di semua outlet', function () {
        $product = app(SaveProduct::class)->handle(null, productData(), $this->pos->outlet, $this->owner)['product'];

        expect(listedAt($product, $this->pos->outlet))->toBeTrue()
            ->and(listedAt($product, $this->outletB))->toBeTrue();
    });

    it('owner memilih produk hanya dijual di outlet B', function () {
        $product = app(SaveProduct::class)->handle(null, productData(['listed_outlet_ids' => [$this->outletB->id]]), $this->pos->outlet, $this->owner)['product'];

        expect(listedAt($product, $this->pos->outlet))->toBeFalse()
            ->and(listedAt($product, $this->outletB))->toBeTrue()
            ->and($product->is_listed)->toBeFalse(); // state outlet form (A)
    });

    it('manager outlet A tidak bisa mengubah status dijual di outlet B', function () {
        $coffee = $this->pos->coffee;
        ProductStock::for($coffee->id, $this->outletB->id)->update(['is_listed' => false]);

        // Kiriman berisi outlet B (tidak dipegang) diabaikan; outlet A dimatikan
        app(SaveProduct::class)->handle($coffee, productData(['name' => $coffee->name, 'listed_outlet_ids' => [$this->outletB->id]]), $this->pos->outlet, $this->managerA);

        expect(listedAt($coffee, $this->pos->outlet))->toBeFalse()
            ->and(listedAt($coffee, $this->outletB))->toBeFalse();
    });

    it('ubah produk tanpa field outlet (API) tidak mengubah status dijual', function () {
        $coffee = $this->pos->coffee;
        ProductStock::for($coffee->id, $this->outletB->id)->update(['is_listed' => false]);

        app(SaveProduct::class)->handle($coffee, productData(['name' => 'Kopi']), $this->pos->outlet, $this->owner);

        expect(listedAt($coffee, $this->outletB))->toBeFalse()->and(listedAt($coffee, $this->pos->outlet))->toBeTrue();
    });

    it('outlet tenant lain di kiriman diabaikan', function () {
        $other = posSetup(openShift: false);
        TenantContext::set($this->pos->tenantId);

        app(SaveProduct::class)->handle($this->pos->coffee, productData(['name' => 'Kopi', 'listed_outlet_ids' => [$other->outlet->id]]), $this->pos->outlet, $this->owner);

        expect(ProductStock::allTenants()->where('outlet_id', $other->outlet->id)->where('product_id', $this->pos->coffee->id)->exists())->toBeFalse()
            ->and(listedAt($this->pos->coffee, $this->pos->outlet))->toBeFalse();
    });
});

describe('opsi habis per outlet (Q43)', function () {
    it('menandai opsi habis di outlet B lalu tersedia lagi tanpa baris ganda', function () {
        $action = app(SetOptionAvailability::class);

        $action->handle($this->pos->large, $this->outletB, false);
        $action->handle($this->pos->large, $this->outletB, false);

        expect(OutletOption::unavailableIds($this->outletB->id))->toBe([$this->pos->large->id])
            ->and(OutletOption::unavailableIds($this->pos->outlet->id))->toBe([])
            ->and(OutletOption::query()->count())->toBe(1);

        $action->handle($this->pos->large, $this->outletB, true);
        expect(OutletOption::unavailableIds($this->outletB->id))->toBe([]);
    });
});

describe('metode bayar per outlet (Q44)', function () {
    it('menonaktifkan QRIS hanya di outlet B', function () {
        $qris = $this->pos->methods['qris'];

        app(SetPaymentMethodAtOutlet::class)->handle($qris, $this->outletB, false);
        app(SetPaymentMethodAtOutlet::class)->handle($qris, $this->outletB, false);

        expect(PaymentMethod::query()->activeAt($this->outletB->id)->pluck('id'))->not->toContain($qris->id)
            ->and(PaymentMethod::query()->activeAt($this->pos->outlet->id)->pluck('id'))->toContain($qris->id)
            ->and(OutletPaymentMethod::query()->count())->toBe(1);
    });

    it('tunai tidak bisa dinonaktifkan di outlet', function () {
        expect(fn () => app(SetPaymentMethodAtOutlet::class)->handle($this->pos->methods['cash'], $this->outletB, false))
            ->toThrow(BusinessException::class);
        expect(OutletPaymentMethod::query()->count())->toBe(0);
    });
});

describe('outlet baru (Q45)', function () {
    it('menyalin status dijual & metode bayar dari outlet sumber; stok dan opsi habis tidak disalin', function () {
        $stock = ProductStock::for($this->pos->coffee->id, $this->outletB->id);
        $stock->forceFill(['is_listed' => false, 'stock_qty' => 7])->save();
        app(SetPaymentMethodAtOutlet::class)->handle($this->pos->methods['qris'], $this->outletB, false);
        app(SetOptionAvailability::class)->handle($this->pos->large, $this->outletB, false);

        $new = app(CreateOutlet::class)->handle('SBY01', 'Surabaya', null, 'Asia/Jakarta', copyMenuFrom: $this->outletB);

        expect(listedAt($this->pos->coffee, $new))->toBeFalse()
            ->and(listedAt($this->pos->croissant, $new))->toBeTrue()
            ->and(Product::query()->atOutlet($new->id)->findOrFail($this->pos->coffee->id)->stock_qty)->toBe(0)
            ->and(PaymentMethod::query()->activeAt($new->id)->pluck('id'))->not->toContain($this->pos->methods['qris']->id)
            ->and(OutletOption::unavailableIds($new->id))->toBe([]);
    });

    it('tanpa sumber: semua produk dijual & semua metode aktif', function () {
        ProductStock::for($this->pos->coffee->id, $this->outletB->id)->update(['is_listed' => false]);

        $new = app(CreateOutlet::class)->handle('SBY01', 'Surabaya', null, 'Asia/Jakarta');

        expect(listedAt($this->pos->coffee, $new))->toBeTrue()
            ->and(PaymentMethod::query()->activeAt($new->id)->count())->toBe(5);
    });
});
