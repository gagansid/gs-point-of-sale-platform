<?php

declare(strict_types=1);

use App\Actions\Payment\CreateDefaultPaymentMethods;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Filament\Dashboard\Resources\Outlets\Pages\ManageOutlets;
use App\Filament\Dashboard\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Filament\Dashboard\Resources\Products\Pages\EditProduct;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\OutletOption;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Menu per outlet di dashboard (ADR 0011, SPEC Q42–Q45).
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outletA = Outlet::factory()->create(['code' => 'JKT01', 'name' => 'Jakarta']);
    TenantContext::set($this->outletA->tenant_id);
    $this->outletB = Outlet::factory()->create(['code' => 'BDG01', 'name' => 'Bandung']);
    $this->owner = User::factory()->owner()->create();
    app(CreateDefaultPaymentMethods::class)->handle();
    $this->product = Product::factory()->create(['name' => 'Es Kopi Susu']);
    actAs($this->owner);
});

function actAs(User $user, ?Outlet $outlet = null): void
{
    test()->actingAs($user);
    CurrentOutlet::set($user, $outlet?->id);
}

function isListed(Product $product, Outlet $outlet): bool
{
    return (bool) Product::query()->atOutlet($outlet->id)->findOrFail($product->id)->is_listed;
}

describe('form produk', function () {
    it('owner menambah produk yang hanya dijual di Bandung', function () {
        Livewire::test(CreateProduct::class)
            ->assertFormFieldExists('listed_outlet_ids')
            ->fillForm(['name' => 'Teh Tarik', 'price' => '15000', 'listed_outlet_ids' => [$this->outletB->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Teh Tarik')->sole();
        expect(isListed($product, $this->outletA))->toBeFalse()->and(isListed($product, $this->outletB))->toBeTrue();
    });

    it('form ubah terisi outlet tempat produk dijual', function () {
        ProductStock::for($this->product->id, $this->outletB->id)->update(['is_listed' => false]);

        Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->assertFormSet(['listed_outlet_ids' => [$this->outletA->id]]);
    });

    it('manager outlet A hanya melihat outlet A dan tidak mengubah Bandung', function () {
        $manager = User::factory()->manager()->forOutlet($this->outletA)->create();
        ProductStock::for($this->product->id, $this->outletB->id)->update(['is_listed' => false]);
        actAs($manager);

        $form = Livewire::test(EditProduct::class, ['record' => $this->product->id])
            ->assertFormSet(['listed_outlet_ids' => [$this->outletA->id]]);

        // Outlet di luar pilihan ditolak validasi form
        $form->fillForm(['listed_outlet_ids' => [$this->outletB->id]])->call('save')->assertHasFormErrors(['listed_outlet_ids.0']);

        $form->fillForm(['listed_outlet_ids' => []])->call('save')->assertHasNoFormErrors();

        expect(isListed($this->product, $this->outletA))->toBeFalse()
            ->and(isListed($this->product, $this->outletB))->toBeFalse();
    });

    it('satu outlet: pilihan outlet tidak tampil', function () {
        $this->outletB->update(['is_active' => false]);

        Livewire::test(CreateProduct::class)->assertFormFieldHidden('listed_outlet_ids');
    });

    it('daftar produk menandai "Tidak dijual" di outlet topbar', function () {
        ProductStock::for($this->product->id, $this->outletB->id)->update(['is_listed' => false]);
        actAs($this->owner, $this->outletB);

        Livewire::test(ListProducts::class)
            ->assertSee('Tidak dijual')
            ->filterTable('is_listed', false)
            ->assertCanSeeTableRecords([$this->product]);
    });
});

describe('opsi habis', function () {
    it('menandai Large habis di outlet topbar saja', function () {
        $group = OptionGroup::factory()->create(['name' => 'Ukuran']);
        $large = Option::factory()->create(['option_group_id' => $group->id, 'name' => 'Large']);
        actAs($this->owner, $this->outletB);

        Livewire::test(ListOptionGroups::class)
            ->callAction(TestAction::make('soldOutOptions')->table($group), ['unavailable' => [$large->id]])
            ->assertHasNoFormErrors();

        expect(OutletOption::unavailableIds($this->outletB->id))->toBe([$large->id])
            ->and(OutletOption::unavailableIds($this->outletA->id))->toBe([]);
    });
});

describe('metode pembayaran', function () {
    it('QRIS dimatikan di Bandung lewat form ubah', function () {
        $qris = PaymentMethod::query()->where('category', 'qris')->sole();

        Livewire::test(ManagePaymentMethods::class)
            ->callAction(TestAction::make('edit')->table($qris), ['name' => 'QRIS', 'requires_reference' => false, 'active_outlet_ids' => [$this->outletA->id]])
            ->assertHasNoFormErrors()
            ->assertSee('1 dari 2 outlet');

        expect(PaymentMethod::query()->activeAt($this->outletB->id)->pluck('id'))->not->toContain($qris->id)
            ->and(PaymentMethod::query()->activeAt($this->outletA->id)->pluck('id'))->toContain($qris->id);
    });

    it('tunai tidak punya pilihan outlet', function () {
        $cash = PaymentMethod::query()->where('category', 'cash')->sole();

        Livewire::test(ManagePaymentMethods::class)
            ->mountAction(TestAction::make('edit')->table($cash))
            ->assertFormFieldHidden('active_outlet_ids');
    });
});

describe('tambah outlet', function () {
    it('menyalin produk yang tidak dijual dari outlet terpilih', function () {
        ProductStock::for($this->product->id, $this->outletB->id)->update(['is_listed' => false]);

        Livewire::test(ManageOutlets::class)
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Surabaya', 'code' => 'SBY01', 'timezone' => 'Asia/Jakarta', 'copy_menu_from' => $this->outletB->id,
            ])
            ->assertHasNoFormErrors();

        expect(isListed($this->product, Outlet::query()->where('code', 'SBY01')->sole()))->toBeFalse();
    });
});
