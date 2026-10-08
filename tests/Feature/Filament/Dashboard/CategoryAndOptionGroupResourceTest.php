<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Categories\Pages\ManageCategories;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\CreateOptionGroup;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\EditOptionGroup;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
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
        ->callAction('create', ['name' => 'Kopi'])
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
