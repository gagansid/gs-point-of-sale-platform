<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Categories\Pages\ManageCategories;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\CreateOptionGroup;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\EditOptionGroup;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->manager = User::factory()->manager()->create();
    $this->actingAs($this->manager);
    TenantContext::set($this->manager->tenant_id);
});

it('menambah, mengubah, dan menghapus kategori tanpa menghapus produknya', function () {
    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Kopi'])
        ->assertHasNoFormErrors();

    $category = Category::query()->sole();
    $product = Product::factory()->forCategory($category)->create();

    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('edit')->table($category), ['name' => 'Kopi Susu']);
    expect($category->refresh()->name)->toBe('Kopi Susu');

    Livewire::test(ManageCategories::class)->callAction(TestAction::make('delete')->table($category));

    expect(Category::query()->count())->toBe(0)
        ->and($product->refresh()->category_id)->toBeNull();
});

it('membuat grup opsi beserta opsinya', function () {
    Livewire::test(CreateOptionGroup::class)
        ->fillForm([
            'name' => 'Ukuran',
            'min_select' => 1,
            'max_select' => 1,
            'options' => [
                ['id' => null, 'name' => 'Regular', 'price_delta' => '0'],
                ['id' => null, 'name' => 'Large', 'price_delta' => '5.000'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $group = OptionGroup::query()->with('options')->sole();
    expect($group->options->pluck('price_delta', 'name')->all())->toBe(['Regular' => '0.00', 'Large' => '5000.00']);
});

it('validasi grup opsi: maksimal tidak boleh lebih kecil dari minimal', function () {
    Livewire::test(CreateOptionGroup::class)
        ->fillForm(['name' => 'X', 'min_select' => 3, 'max_select' => 1, 'options' => [['id' => null, 'name' => 'A', 'price_delta' => '0']]])
        ->call('create')
        ->assertHasFormErrors(['max_select']);
});

it('mengubah grup opsi: opsi lama dipertahankan dengan id yang sama', function () {
    $group = OptionGroup::factory()->create();
    $large = Option::factory()->create(['option_group_id' => $group->id, 'tenant_id' => $group->tenant_id, 'name' => 'Large', 'price_delta' => '5000.00']);

    $component = Livewire::test(EditOptionGroup::class, ['record' => $group->getRouteKey()]);
    $options = array_values($component->get('data.options'));
    expect($options[0]['price_delta'])->toBe('5000');

    $component->fillForm(['options' => [
        ['id' => $large->id, 'name' => 'Large', 'price_delta' => '6.000'],
        ['id' => null, 'name' => 'Extra Large', 'price_delta' => '9.000'],
    ]])->call('save')->assertHasNoFormErrors();

    expect(Option::query()->find($large->id)?->price_delta)->toBe('6000.00')
        ->and(Option::query()->count())->toBe(2);
});

it('hapus massal kategori & grup opsi hanya menyentuh data tenant sendiri', function () {
    $mine = Category::factory()->count(2)->create();
    $foreignCategory = TenantContext::run(Tenant::factory()->create()->id, fn () => Category::factory()->create());

    Livewire::test(ManageCategories::class)
        ->selectTableRecords([...$mine->pluck('id')->all(), $foreignCategory->id])
        ->callAction(TestAction::make('bulkDelete')->table()->bulk());

    expect(Category::query()->count())->toBe(0)
        ->and(Category::allTenants()->whereKey($foreignCategory->id)->exists())->toBeTrue();

    $group = OptionGroup::factory()->create();

    Livewire::test(ListOptionGroups::class)
        ->selectTableRecords([$group])
        ->callAction(TestAction::make('bulkDelete')->table()->bulk());

    expect(OptionGroup::query()->count())->toBe(0);
});

it('filter grup opsi: wajib/opsional dan dipakai/belum', function () {
    $required = OptionGroup::factory()->create(['min_select' => 1, 'max_select' => 1]);
    $optional = OptionGroup::factory()->create(['min_select' => 0, 'max_select' => 3]);
    Product::factory()->create()->optionGroups()->attach($required->id, ['sort_order' => 0]);

    Livewire::test(ListOptionGroups::class)
        ->filterTable('required', true)
        ->assertCanSeeTableRecords([$required])
        ->assertCanNotSeeTableRecords([$optional])
        ->resetTableFilters()
        ->filterTable('used', false)
        ->assertCanSeeTableRecords([$optional])
        ->assertCanNotSeeTableRecords([$required]);
});

it('status kategori: tab aktif/nonaktif, nonaktifkan berkonfirmasi, aktifkan langsung', function () {
    $active = Category::factory()->create(['name' => 'Kopi']);
    $inactive = Category::factory()->create(['is_active' => false]);

    $action = Livewire::test(ManageCategories::class)
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active])
        ->set('activeTab', 'all')
        ->assertActionHidden(TestAction::make('activate')->table($active))
        ->mountAction(TestAction::make('deactivate')->table($active))
        ->instance()->getMountedAction();

    expect($action?->isConfirmationRequired())->toBeTrue()
        ->and($active->refresh()->is_active)->toBeTrue();

    Livewire::test(ManageCategories::class)->callAction(TestAction::make('deactivate')->table($active));
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('activate')->table($inactive));

    expect($active->refresh()->is_active)->toBeFalse()
        ->and($inactive->refresh()->is_active)->toBeTrue();
});

it('form grup opsi: tombol Tambah opsi menambah baris tabel lalu tersimpan berurutan', function () {
    $component = Livewire::test(CreateOptionGroup::class)
        ->fillForm(['name' => 'Gula', 'min_select' => 0, 'max_select' => 1, 'is_active' => false])
        ->set('data.options', [])
        ->callAction(TestAction::make('addOption')->schemaComponent('optionsSection', schema: 'form'))
        ->callAction(TestAction::make('addOption')->schemaComponent('optionsSection', schema: 'form'));

    $keys = array_keys($component->get('data.options'));
    expect($keys)->toHaveCount(2);

    $component
        ->set("data.options.{$keys[0]}.name", 'Normal')
        ->set("data.options.{$keys[1]}.name", 'Less sugar')
        ->call('create')
        ->assertHasNoFormErrors();

    $group = OptionGroup::query()->where('name', 'Gula')->sole();
    expect($group->is_active)->toBeFalse()
        ->and($group->options()->pluck('name')->all())->toBe(['Normal', 'Less sugar']);
});
