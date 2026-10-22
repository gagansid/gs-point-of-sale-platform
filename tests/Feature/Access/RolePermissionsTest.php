<?php

declare(strict_types=1);

use App\Actions\Outlet\CreateOutlet;
use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SetEmployeeActive;
use App\Actions\User\UpdateRolePermissions;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\Home;
use App\Filament\Dashboard\Pages\Reports;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Filament\Dashboard\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Dashboard\Resources\Employees\EmployeeResource;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Models\ActivityLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\RolePermissions;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

/**
 * Hak akses per bisnis (ADR 0026): owner mengatur permission manager/supervisor/kasir; bawaan = UserRole::permissions().
 * Menu dashboard = dashboard.access + permission menu. user.manage yang didelegasikan hanya untuk role di bawahnya.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create(['name' => 'Maya']);
});

/** @param  list<string>|null  $permissions */
function rpSetRole(object $test, UserRole $role, ?array $permissions): array
{
    $result = app(UpdateRolePermissions::class)->handle($test->owner, $role, $permissions);
    $test->manager->refresh();

    return $result;
}

function rpActAs(object $test, User $user): void
{
    RolePermissions::flush();
    $test->actingAs($user);
    CurrentOutlet::set($user);
}

it('bawaan: tanpa pengaturan, hak akses sama dengan role; owner selalu semua', function () {
    expect($this->manager->grantedPermissions())->toBe(UserRole::Manager->grantedPermissions())
        ->and($this->owner->grantedPermissions())->toBe(UserRole::PERMISSIONS)
        ->and($this->manager->hasFullDashboard())->toBeTrue()
        ->and($this->pos->supervisor->hasFullDashboard())->toBeFalse()
        ->and(RolePermissions::isCustomized(UserRole::Manager, $this->pos->tenantId))->toBeFalse();
});

it('owner mencabut akses manager: menu & API ikut tertutup, /auth/me memuat daftar baru', function () {
    $without = array_values(array_diff(UserRole::Manager->grantedPermissions(), ['product.manage', 'report.view', 'report.view_shift', 'report.export']));
    rpSetRole($this, UserRole::Manager, $without);

    rpActAs($this, $this->manager);
    expect(ProductResource::canViewAny())->toBeFalse()
        ->and(Reports::canAccess())->toBeFalse()
        ->and(OrderResource::canViewAny())->toBeTrue();

    // Beranda (ringkasan omzet) tidak boleh → diarahkan ke menu pertama yang boleh
    $this->get(Home::getUrl())->assertRedirect();

    freshAuth();
    $token = userToken($this->manager);
    $this->withToken($token)->postJson('/api/v1/products', ['name' => 'Baru', 'price' => '10000'], apiHeaders())->assertForbidden();
    freshAuth();
    $me = $this->withToken($token)->getJson('/api/v1/auth/me', apiHeaders())->assertOk()->json('data.user.permissions');
    expect($me)->not->toContain('product.manage')->toContain('order.view_all');
});

it('owner memberi supervisor akses dashboard & penjualan: menu Penjualan muncul', function () {
    rpActAs($this, $this->pos->supervisor->forceFill(['email_verified_at' => now()]));
    expect(OrderResource::canViewAny())->toBeFalse();

    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Supervisor, [...UserRole::Supervisor->grantedPermissions(), 'dashboard.access']);

    rpActAs($this, $this->pos->supervisor);
    expect($this->pos->supervisor->hasFullDashboard())->toBeTrue()
        ->and(OrderResource::canViewAny())->toBeTrue()
        ->and(ProductResource::canViewAny())->toBeFalse();
});

it('permission tersirat ikut diberikan; kembali ke bawaan menghapus pengaturan', function () {
    $granted = rpSetRole($this, UserRole::Cashier, ['order.create', 'member.manage', 'report.view']);

    expect($granted)->toContain('member.view', 'report.view_shift', 'report.view_own')
        ->and(RolePermissions::isCustomized(UserRole::Cashier, $this->pos->tenantId))->toBeTrue();

    expect(rpSetRole($this, UserRole::Cashier, null))->toBe(UserRole::Cashier->grantedPermissions())
        ->and(RolePermission::query()->count())->toBe(0);
});

it('permission khusus owner tidak bisa diberikan, walau tersimpan langsung di DB', function () {
    expect(fn () => rpSetRole($this, UserRole::Manager, ['billing.manage']))->toThrow(BusinessException::class)
        ->and(fn () => rpSetRole($this, UserRole::Owner, []))->toThrow(BusinessException::class);

    RolePermission::query()->create(['role' => UserRole::Manager, 'permissions' => ['billing.manage', 'access.manage', 'order.create']]);
    RolePermissions::flush();

    rpActAs($this, $this->manager);
    expect($this->manager->can('billing.manage'))->toBeFalse()
        ->and($this->manager->can('access.manage'))->toBeFalse()
        ->and($this->manager->can('order.create'))->toBeTrue()
        ->and(SubscriptionSettings::canAccess())->toBeFalse();
});

it('hanya owner yang boleh mengatur hak akses', function () {
    try {
        app(UpdateRolePermissions::class)->handle($this->manager, UserRole::Cashier, []);
        $this->fail('Seharusnya ditolak');
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe('FORBIDDEN')->and($e->status)->toBe(403);
    }
});

it('isolasi tenant: pengaturan bisnis lain tidak berlaku di sini', function () {
    $other = posSetup();
    $otherOwner = User::factory()->owner()->create(['tenant_id' => $other->tenantId]);
    TenantContext::run($other->tenantId, fn () => app(UpdateRolePermissions::class)->handle($otherOwner, UserRole::Manager, ['order.create']));
    TenantContext::set($this->pos->tenantId);
    RolePermissions::flush();

    expect($this->manager->can('product.manage'))->toBeTrue()
        ->and(RolePermissions::isCustomized(UserRole::Manager, $this->pos->tenantId))->toBeFalse()
        ->and(RolePermissions::isCustomized(UserRole::Manager, $other->tenantId))->toBeTrue();
});

describe('user.manage didelegasikan ke manager', function () {
    beforeEach(function () {
        rpSetRole($this, UserRole::Manager, [...UserRole::Manager->grantedPermissions(), 'user.manage']);
        rpActAs($this, $this->manager);
    });

    $employee = fn (string $username, UserRole $role, ?string $outletId = null): EmployeeData => EmployeeData::fromArray([
        'name' => ucfirst($username), 'role' => $role->value, 'username' => $username, 'email' => "{$username}@kopi.test",
        'pin' => '481920', 'password' => 'rahasia123', 'outlet_ids' => array_filter([$outletId]),
    ]);

    it('boleh menambah & mengubah kasir/supervisor', function () use ($employee) {
        $dodi = app(SaveEmployee::class)->handle(null, $employee('dodi', UserRole::Cashier, $this->pos->outlet->id), $this->manager)['user'];

        expect($dodi->role)->toBe(UserRole::Cashier)
            ->and(EmployeeResource::canViewAny())->toBeTrue()
            ->and($this->manager->can('update', $this->pos->cashier))->toBeTrue();
    });

    it('tidak boleh membuat manager/owner, mengubah owner, sesama manager, atau dirinya', function () use ($employee) {
        $other = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
        $forbidden = fn (Closure $call): string => (function () use ($call): string {
            try {
                $call();
            } catch (BusinessException $e) {
                return $e->errorCode;
            }

            return 'lolos';
        })();

        expect($forbidden(fn () => app(SaveEmployee::class)->handle(null, $employee('rudi', UserRole::Manager), $this->manager)))->toBe('FORBIDDEN')
            ->and($forbidden(fn () => app(SaveEmployee::class)->handle(null, $employee('sinta', UserRole::Owner), $this->manager)))->toBe('FORBIDDEN')
            // Menaikkan kasir jadi manager juga ditolak
            ->and($forbidden(fn () => app(SaveEmployee::class)->handle($this->pos->cashier, $employee('budi', UserRole::Manager), $this->manager)))->toBe('FORBIDDEN')
            ->and($forbidden(fn () => app(SetEmployeeActive::class)->handle($this->owner, false, $this->manager)))->toBe('FORBIDDEN')
            ->and($forbidden(fn () => app(SetEmployeeActive::class)->handle($other, false, $this->manager)))->toBe('FORBIDDEN')
            ->and($this->manager->can('update', $this->owner))->toBeFalse()
            ->and($this->manager->can('update', $this->manager))->toBeFalse()
            ->and($this->owner->is_active)->toBeTrue();

        freshAuth();
        $this->withToken(userToken($this->manager))->putJson("/api/v1/users/{$this->owner->id}", ['name' => 'Diubah'], apiHeaders())->assertForbidden();
    });
});

describe('perbaikan review', function () {
    it('Riwayat perubahan: log "Diubah" hak akses tetap menyebut role', function () {
        rpSetRole($this, UserRole::Manager, ['order.create']);
        rpSetRole($this, UserRole::Manager, ['order.create', 'order.void']);

        $updated = ActivityLog::query()->where('subject_type', RolePermission::class)->where('event', 'updated')->sole();
        expect($updated->properties['role'])->toBe('manager');

        rpActAs($this, $this->owner);
        Livewire\Livewire::test(ListActivityLogs::class)
            ->assertSee('Role Manager')->assertDontSee('Role —');
    });

    it('Tambah outlet didelegasikan: pembuat langsung memegang outlet barunya', function () {
        rpSetRole($this, UserRole::Manager, [...UserRole::Manager->grantedPermissions(), 'outlet.manage']);
        rpActAs($this, $this->manager);

        $outlet = app(CreateOutlet::class)->handle('JKT09', 'Cabang Baru', null, 'Asia/Jakarta', null, $this->manager);

        expect($this->manager->canAccessOutlet($outlet->id))->toBeTrue()
            ->and($this->owner->canAccessOutlet($outlet->id))->toBeTrue();
    });

    it('Kelola karyawan didelegasikan tidak melewati toggle Akses kasir web; owner selalu boleh', function () {
        rpSetRole($this, UserRole::Manager, [...UserRole::Manager->grantedPermissions(), 'user.manage']);
        $this->manager->forceFill(['web_cashier_access' => false])->save();

        expect($this->manager->fresh()->canUseWebCashier())->toBeFalse()
            ->and($this->owner->forceFill(['web_cashier_access' => false])->canUseWebCashier())->toBeTrue();
    });

    it('menyimpan hak akses role yang sama berulang tetap satu baris', function () {
        rpSetRole($this, UserRole::Cashier, ['order.create']);
        rpSetRole($this, UserRole::Cashier, ['order.create', 'order.reprint']);

        expect(RolePermission::query()->where('role', 'cashier')->count())->toBe(1);
    });

    it('job queue membaca ulang hak akses (cache statis dikosongkan tiap job)', function () {
        expect($this->manager->can('product.manage'))->toBeTrue(); // cache tenant terisi

        // Diubah langsung di DB (seperti dari request lain), cache proses ini masih lama
        RolePermission::query()->create(['role' => UserRole::Manager, 'permissions' => ['order.create']]);
        $managerId = $this->manager->id;

        // Job closure diserialisasi: hasil dikirim balik lewat cache (driver array di test, satu proses)
        dispatch(function () use ($managerId): void {
            Cache::put('rp-job-can', User::query()->findOrFail($managerId)->can('product.manage'));
        });

        expect(Cache::get('rp-job-can'))->toBeFalse();
    });
});
