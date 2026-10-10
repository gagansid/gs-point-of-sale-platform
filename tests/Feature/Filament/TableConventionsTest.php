<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Dashboard\Resources\Categories\Pages\ManageCategories;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Filament\Dashboard\Resources\Orders\Pages\ListOrders;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Dashboard\Resources\Shifts\Pages\ListShifts;
use Livewire\Attributes\Url;

/*
 * Konvensi semua halaman daftar (docs/standards/ui/components/table.md): filter & tab tidak ditulis
 * ke URL; keduanya disimpan di session. Properti harus dideklarasikan ulang di class halaman itu
 * sendiri — deklarasi di trait diabaikan PHP bila identik dengan properti ListRecords.
 */
it('filter tidak ditulis ke URL', function (string $page) {
    $property = new ReflectionProperty($page, 'tableFilters');

    expect($property->getDeclaringClass()->getName())->toBe($page)
        ->and($property->getAttributes(Url::class))->toBeEmpty();
})->with([
    ListProducts::class,
    ManageCategories::class,
    ListOptionGroups::class,
    ListOrders::class,
    ListShifts::class,
    ListTenants::class,
]);

it('tab tidak ditulis ke URL', function (string $page) {
    $property = new ReflectionProperty($page, 'activeTab');

    expect($property->getDeclaringClass()->getName())->toBe($page)
        ->and($property->getAttributes(Url::class))->toBeEmpty();
})->with([
    ListProducts::class,
    ListOrders::class,
    ListShifts::class,
    ListTenants::class,
]);
