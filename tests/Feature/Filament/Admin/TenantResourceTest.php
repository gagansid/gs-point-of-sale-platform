<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\Admin;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

function validTenantForm(array $override = []): array
{
    return [
        'name' => 'Kopi Pagi',
        'slug' => 'kopi-pagi',
        'business_type' => 'cafe',
        'status' => 'trial',
        'subscription_ends_at' => now()->addDays(14)->toDateString(),
        'outlet_name' => 'Kopi Pagi Sudirman',
        'outlet_code' => 'jkt01',
        'outlet_timezone' => 'Asia/Jakarta',
        'owner_name' => 'Rina',
        'owner_email' => 'Rina@KopiPagi.test',
        'owner_password' => 'rahasia123',
        ...$override,
    ];
}

it('menampilkan semua tenant lintas bisnis beserta jumlah karyawan', function () {
    $tenants = Tenant::factory()->count(3)->create();
    User::factory()->count(2)->create(['tenant_id' => $tenants[0]->id]);

    Livewire::test(ListTenants::class)
        ->assertCanSeeTableRecords($tenants)
        ->assertTableColumnStateSet('users_count', 2, $tenants[0]);
});

it('membuat tenant beserta outlet dan owner pertama', function () {
    Livewire::test(CreateTenant::class)
        ->fillForm(validTenantForm())
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'kopi-pagi')->sole();
    $outlet = Outlet::forTenant($tenant->id)->sole();
    $owner = User::forTenant($tenant->id)->sole();

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($outlet->code)->toBe('JKT01')
        ->and($outlet->timezone)->toBe('Asia/Jakarta')
        ->and($owner->role)->toBe(UserRole::Owner)
        ->and($owner->email)->toBe('rina@kopipagi.test')
        ->and($owner->outlet_id)->toBeNull()
        ->and(Hash::check('rahasia123', (string) $owner->password))->toBeTrue();
});

it('memvalidasi form tenant baru', function (array $override, string $field) {
    User::factory()->create(['email' => 'dipakai@tenantlain.test']);
    Tenant::factory()->create(['slug' => 'sudah-ada']);

    Livewire::test(CreateTenant::class)
        ->fillForm(validTenantForm($override))
        ->call('create')
        ->assertHasFormErrors([$field]);

    expect(Tenant::query()->where('name', 'Kopi Pagi')->exists())->toBeFalse();
})->with([
    'email owner dipakai tenant lain' => [['owner_email' => 'dipakai@tenantlain.test'], 'owner_email'],
    'slug sudah dipakai' => [['slug' => 'sudah-ada'], 'slug'],
    'kata sandi lemah' => [['owner_password' => 'abc'], 'owner_password'],
    'kode outlet berisi simbol' => [['outlet_code' => 'JKT-01'], 'outlet_code'],
    'nama kosong' => [['name' => ''], 'name'],
]);

it('menangguhkan dan mengaktifkan kembali tenant', function () {
    $tenant = Tenant::factory()->create();

    Livewire::test(ListTenants::class)
        ->callAction(TestAction::make('suspend')->table($tenant));
    expect($tenant->refresh()->status)->toBe(TenantStatus::Suspended);

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->callAction('activate');
    expect($tenant->refresh()->status)->toBe(TenantStatus::Active);
});

it('mengubah profil & masa langganan tanpa mengubah status', function () {
    $tenant = Tenant::factory()->suspended()->create();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['name' => 'Nama Baru', 'subscription_ends_at' => '2027-12-31'])
        ->call('save')
        ->assertHasNoFormErrors();

    $tenant->refresh();
    expect($tenant->name)->toBe('Nama Baru')
        ->and($tenant->subscription_ends_at?->toDateString())->toBe('2027-12-31')
        ->and($tenant->status)->toBe(TenantStatus::Suspended);
});

it('menampilkan statistik pemakaian tenant', function () {
    $outlet = Outlet::factory()->create();
    User::factory()->owner()->forOutlet($outlet)->create(['email' => 'owner@stat.test']);

    $this->get("/admin/tenants/{$outlet->tenant_id}")
        ->assertOk()
        ->assertSee('owner@stat.test');
});

it('tab tenant: aktif, suspended, dan segera berakhir', function () {
    $active = Tenant::factory()->create(['status' => TenantStatus::Active, 'subscription_ends_at' => now()->addMonths(3)]);
    $suspended = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $expiring = Tenant::factory()->create(['status' => TenantStatus::Active, 'subscription_ends_at' => now()->addDays(3)]);

    Livewire::test(ListTenants::class)
        ->set('activeTab', 'suspended')
        ->assertCanSeeTableRecords([$suspended])
        ->assertCanNotSeeTableRecords([$active, $expiring])
        ->set('activeTab', 'expiring')
        ->assertCanSeeTableRecords([$expiring])
        ->assertCanNotSeeTableRecords([$active, $suspended]);
});
