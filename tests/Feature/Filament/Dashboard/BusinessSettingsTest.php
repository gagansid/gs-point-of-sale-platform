<?php

declare(strict_types=1);

use App\Actions\Tenant\UpdateBusinessSettings;
use App\Actions\Tenant\UpdateTenantPreferences;
use App\Enums\BusinessType;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\BusinessSettings;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantAppearance;
use App\Support\TenantContext;
use App\Support\TenantPreferences;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Pengaturan → Bisnis & tampilan (Q128): profil bisnis, warna utama, sidebar, mode bawaan — level owner. */
beforeEach(function () {
    Storage::fake('public');
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create();
    $this->tenant = Tenant::query()->findOrFail($this->outlet->tenant_id);
    TenantContext::set($this->tenant->id);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id, 'email_verified_at' => now()]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

function businessData(array $overrides = []): array
{
    return ['name' => 'Kopi Senja', 'business_type' => 'cafe', 'color' => 'teal', 'custom_color' => null, 'sidebar' => 'light',
        'theme_mode' => 'dark', 'logo' => null, 'show_setup_guide' => true, ...$overrides];
}

it('hanya owner yang bisa membuka setelan bisnis & tampilan', function () {
    $this->get(BusinessSettings::getUrl())->assertOk()->assertSee('Tampilan dashboard')->assertSee('Toska');

    foreach ([User::factory()->manager()->forOutlet($this->outlet)->create(), User::factory()->cashier()->forOutlet($this->outlet)->create()] as $user) {
        $this->actingAs($user);
        expect(BusinessSettings::canAccess())->toBeFalse();
        $this->get(BusinessSettings::getUrl())->assertForbidden();
    }
});

it('menyimpan profil & tampilan; kode bisnis tetap; nilai bawaan tidak disimpan', function () {
    $slug = $this->tenant->slug;
    app(UpdateBusinessSettings::class)->handle($this->tenant, businessData(['business_type' => 'retail']));
    $tenant = $this->tenant->refresh();

    expect($tenant->name)->toBe('Kopi Senja')->and($tenant->slug)->toBe($slug)->and($tenant->business_type)->toBe(BusinessType::Retail)
        ->and(TenantAppearance::hex($tenant))->toBe('#0f766e')
        ->and(TenantAppearance::sidebar($tenant))->toBe('light')
        ->and(TenantAppearance::themeMode($tenant)->value)->toBe('dark');

    app(UpdateBusinessSettings::class)->handle($tenant, businessData(['color' => 'navy', 'sidebar' => 'colored', 'theme_mode' => 'light']));
    expect($tenant->refresh()->preferences)->toBeNull()->and(TenantAppearance::isDefault($tenant))->toBeTrue()
        ->and(TenantAppearance::css($tenant))->toBeNull();
});

it('warna bebas divalidasi: hex & kontras teks putih; logo hanya raster di folder bisnis sendiri', function () {
    $action = app(UpdateBusinessSettings::class);

    expect(fn () => $action->handle($this->tenant, businessData(['color' => 'custom', 'custom_color' => 'merah'])))->toThrow(BusinessException::class, 'hex')
        ->and(fn () => $action->handle($this->tenant, businessData(['color' => 'custom', 'custom_color' => '#fde68a'])))->toThrow(BusinessException::class, 'terlalu terang')
        ->and(fn () => $action->handle($this->tenant, businessData(['logo' => 'tenants/lain/logo.png'])))->toThrow(BusinessException::class, 'PNG')
        ->and(fn () => $action->handle($this->tenant, businessData(['logo' => UpdateBusinessSettings::logoDirectory($this->tenant).'/logo.svg'])))->toThrow(BusinessException::class)
        ->and(fn () => $action->handle($this->tenant, businessData(['name' => ' '])))->toThrow(BusinessException::class, 'Nama bisnis');

    $action->handle($this->tenant, businessData(['color' => 'custom', 'custom_color' => '#7C2D12']));
    expect(TenantAppearance::hex($this->tenant->refresh()))->toBe('#7c2d12')
        ->and(TenantAppearance::palette('#7c2d12')[600])->toBe('#7c2d12');

    // Logo lama dihapus saat diganti
    $dir = UpdateBusinessSettings::logoDirectory($this->tenant);
    Storage::disk('public')->put($dir.'/lama.png', 'x');
    $action->handle($this->tenant, businessData(['logo' => $dir.'/lama.png']));
    $action->handle($this->tenant->refresh(), businessData(['logo' => null]));
    Storage::disk('public')->assertMissing($dir.'/lama.png');
});

it('dashboard memakai warna & mode bawaan bisnis sendiri, bukan bisnis lain', function () {
    app(UpdateBusinessSettings::class)->handle($this->tenant, businessData());

    // Di luar tenant context agar benar-benar bisnis lain
    TenantContext::forget();
    $other = Outlet::factory()->create();
    TenantContext::set($this->tenant->id);
    $otherTenant = Tenant::query()->findOrFail($other->tenant_id);
    expect($otherTenant->id)->not->toBe($this->tenant->id);
    app(UpdateTenantPreferences::class)->handle($otherTenant, [TenantAppearance::COLOR => 'brick']);

    $html = $this->get('/dashboard')->assertOk()->getContent();
    expect($html)->toContain('id="gs-tenant-appearance"')->toContain('--primary:#0f766e')->not->toContain('#b42318')
        ->toContain('--sidebar-bg:#ffffff')
        ->and(Filament::getCurrentPanel()->getDefaultThemeMode()->value)->toBe('dark');
});

it('halaman menyimpan lewat form dan bisa menampilkan lagi panduan yang disembunyikan', function () {
    app(UpdateTenantPreferences::class)->handle($this->tenant, [TenantPreferences::SETUP_DISMISSED => true]);

    Livewire::test(BusinessSettings::class)
        ->assertSet('data.show_setup_guide', false)
        ->set('data.color', 'purple')
        ->set('data.show_setup_guide', true)
        ->call('save')
        ->assertNotified('Setelan bisnis disimpan');

    $tenant = $this->tenant->refresh();
    expect(TenantAppearance::hex($tenant))->toBe('#6d28d9')->and(TenantPreferences::setupDismissed($tenant))->toBeFalse();
});
