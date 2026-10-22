<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Dashboard\Pages\AccessSettings;
use App\Filament\Dashboard\Resources\ActivityLogs\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\PermissionCatalog;
use App\Support\RolePermissions;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Pengaturan → Hak akses (ADR 0026): centang per role, simpan, kembali ke bawaan, tercatat di Riwayat perubahan. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

it('katalog memuat tepat semua permission yang bisa didelegasikan, tanpa yang khusus owner', function () {
    $delegable = array_values(array_filter(UserRole::PERMISSIONS, UserRole::isDelegable(...)));

    expect(PermissionCatalog::permissions())->toEqualCanonicalizing($delegable)
        ->and(PermissionCatalog::permissions())->not->toContain(...UserRole::OWNER_ONLY)
        ->and(array_map(fn (UserRole $r): string => $r->value, PermissionCatalog::roles()))->toBe(['manager', 'supervisor', 'cashier']);
});

it('owner membuka halaman: form terisi hak akses bawaan per role & kelompok', function () {
    $this->get(AccessSettings::getUrl())->assertOk()->assertSee('Hak akses')->assertSee('Buka dashboard')->assertSee('Bawaan');

    Livewire::test(AccessSettings::class)
        ->assertSet('data.manager.dashboard', fn (array $v): bool => in_array('dashboard.access', $v, true) && in_array('report.view', $v, true))
        ->assertSet('data.supervisor.dashboard', fn (array $v): bool => ! in_array('dashboard.access', $v, true) && in_array('report.view_shift', $v, true))
        ->assertSet('data.cashier.settings', []);
});

it('simpan: supervisor diberi dashboard, role lain tetap bawaan (tanpa baris)', function () {
    $page = Livewire::test(AccessSettings::class);
    $page->set('data.supervisor.dashboard', [...$page->get('data.supervisor.dashboard'), 'dashboard.access'])
        ->call('save')
        ->assertNotified('Hak akses disimpan');

    RolePermissions::flush();
    expect(RolePermission::query()->pluck('role')->map->value->all())->toBe(['supervisor'])
        ->and(RolePermissions::allows(UserRole::Supervisor, $this->pos->tenantId, 'dashboard.access'))->toBeTrue()
        ->and($this->pos->supervisor->fresh()->hasFullDashboard())->toBeTrue();

    // Tercatat & terbaca di Riwayat perubahan
    $log = ActivityLog::query()->where('subject_type', RolePermission::class)->sole();
    expect(ActivityLogResource::changes($log))->toContain([
        'field' => 'permissions', 'label' => 'Hak akses', 'old' => '—', 'new' => 'Diberi: Buka dashboard',
    ]);
});

it('mencabut lalu centang kembali seperti bawaan = disimpan sebagai bawaan', function () {
    $page = Livewire::test(AccessSettings::class);
    $original = $page->get('data.manager.catalog');

    $page->set('data.manager.catalog', [])->call('save');
    RolePermissions::flush();
    expect(RolePermissions::allows(UserRole::Manager, $this->pos->tenantId, 'product.manage'))->toBeFalse();

    Livewire::test(AccessSettings::class)->set('data.manager.catalog', $original)->call('save');
    RolePermissions::flush();
    expect(RolePermission::query()->count())->toBe(0)
        ->and(RolePermissions::allows(UserRole::Manager, $this->pos->tenantId, 'product.manage'))->toBeTrue();
});

it('kembalikan bawaan per role', function () {
    RolePermission::query()->create(['role' => UserRole::Cashier, 'permissions' => ['order.create']]);
    RolePermissions::flush();

    Livewire::test(AccessSettings::class)
        ->assertSet('data.cashier.transactions', ['order.create'])
        ->callAction(TestAction::make('reset_cashier')->schemaComponent('role-cashier.reset-cashier'))
        ->assertNotified('Hak akses Kasir kembali ke bawaan')
        ->assertSet('data.cashier.transactions', fn (array $v): bool => in_array('order.reprint', $v, true));

    expect(RolePermission::query()->count())->toBe(0);
});

it('permission: hanya owner; manager, supervisor, kasir ditolak', function () {
    foreach ([User::factory()->manager()->forOutlet($this->pos->outlet)->create(), $this->pos->supervisor, $this->pos->cashier] as $user) {
        $this->actingAs($user);
        RolePermissions::flush();
        expect(AccessSettings::canAccess())->toBeFalse();
    }

    $this->actingAs(User::factory()->manager()->forOutlet($this->pos->outlet)->create());
    $this->get(AccessSettings::getUrl())->assertForbidden();
});

it('isolasi tenant: menyimpan tidak mengubah bisnis lain', function () {
    $other = posSetup();
    TenantContext::set($this->pos->tenantId);

    $page = Livewire::test(AccessSettings::class);
    $page->set('data.manager.customers', [])->call('save');

    expect(RolePermission::query()->forTenant($other->tenantId)->count())->toBe(0)
        ->and(RolePermission::query()->forTenant($this->pos->tenantId)->count())->toBe(1);
});
