<?php

declare(strict_types=1);

use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SetEmployeePermissions;
use App\Actions\User\UpdateRolePermissions;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\AccessSettings;
use App\Filament\Dashboard\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\RolePermissions;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Pengecualian hak akses per karyawan (ADR 0026, opsi B): selisih terhadap role, diatur owner. */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    $this->budi = $this->pos->cashier;
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

function epFail(Closure $call): string
{
    try {
        $call();
    } catch (BusinessException $e) {
        return $e->errorCode;
    }

    return 'lolos';
}

it('kasir Budi diberi void & dicabut diskon: hanya Budi yang berubah, disimpan sebagai selisih', function () {
    $wanted = [...array_diff(UserRole::Cashier->grantedPermissions(), ['order.discount']), 'order.void'];
    $granted = app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, array_values($wanted));

    $budi = $this->budi->fresh();
    expect($granted)->toContain('order.void')->not->toContain('order.discount')
        ->and($budi->permissions_granted)->toBe(['order.void'])
        ->and($budi->permissions_revoked)->toBe(['order.discount'])
        ->and($budi->can('order.void'))->toBeTrue()
        ->and($budi->hasPermissionOverrides())->toBeTrue();

    // Kasir lain tetap mengikuti role
    $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
    expect($other->can('order.void'))->toBeFalse()->and($other->can('order.discount'))->toBeTrue();
});

it('perubahan hak akses role tetap berlaku untuk izin lain karyawan berpengecualian', function () {
    app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, [...UserRole::Cashier->grantedPermissions(), 'order.void']);
    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Cashier, [...UserRole::Cashier->grantedPermissions(), 'order.refund']);
    RolePermissions::flush();

    $budi = $this->budi->fresh();
    expect($budi->can('order.refund'))->toBeTrue()->and($budi->can('order.void'))->toBeTrue();
});

it('izin tersirat ikut; mencabut izin dasar menggugurkan izin yang bergantung padanya', function () {
    $granted = app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, [...UserRole::Cashier->grantedPermissions(), 'member.manage']);
    expect($granted)->toContain('member.manage', 'member.view');

    // Kelola member masih dicentang → Lihat member tetap ikut (sama dengan aturan halaman role)
    $granted = app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, array_values(array_diff($granted, ['member.view'])));
    expect($granted)->toContain('member.view', 'member.manage');

    $granted = app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, array_values(array_diff($granted, ['member.view', 'member.manage', 'member.create'])));
    expect($granted)->not->toContain('member.view')->not->toContain('member.manage')->not->toContain('member.create');

    // Dasar dicabut sementara turunan berasal dari role/tambahan: turunan ikut gugur, berantai
    expect(RolePermissions::applyOverrides(['member.view', 'member.manage'], [], ['member.view']))->toBe([])
        ->and(RolePermissions::applyOverrides(['report.view', 'report.view_shift', 'report.view_own'], [], ['report.view_own']))->toBe([]);
});

it('ikuti role (null) & sama dengan role menghapus pengecualian; ganti role juga', function () {
    app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, [...UserRole::Cashier->grantedPermissions(), 'order.void']);
    app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, null);
    expect($this->budi->fresh()->hasPermissionOverrides())->toBeFalse();

    app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, [...UserRole::Cashier->grantedPermissions(), 'order.void']);
    app(SaveEmployee::class)->handle($this->budi->fresh(), EmployeeData::fromArray([
        'name' => 'Budi', 'role' => 'supervisor', 'username' => 'budi', 'email' => 'budi@kopi.test', 'password' => 'rahasia123',
        'outlet_ids' => [$this->pos->outlet->id],
    ]), $this->owner);

    expect($this->budi->fresh()->hasPermissionOverrides())->toBeFalse();
});

it('khusus owner & pengaman: owner tidak bisa diberi pengecualian; izin khusus owner ditolak; hanya owner yang boleh', function () {
    expect(epFail(fn () => app(SetEmployeePermissions::class)->handle($this->owner, $this->owner, [])))->toBe('VALIDATION_ERROR')
        ->and(epFail(fn () => app(SetEmployeePermissions::class)->handle($this->owner, $this->budi, ['billing.manage'])))->toBe('VALIDATION_ERROR');

    $manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
    app(UpdateRolePermissions::class)->handle($this->owner, UserRole::Manager, [...UserRole::Manager->grantedPermissions(), 'user.manage']);
    RolePermissions::flush();
    expect(epFail(fn () => app(SetEmployeePermissions::class)->handle($manager->fresh(), $this->budi, ['order.void'])))->toBe('FORBIDDEN');

    // Disisipkan langsung ke DB pun tidak berlaku
    $this->budi->forceFill(['permissions_granted' => ['billing.manage', 'access.manage']])->save();
    expect($this->budi->fresh()->can('billing.manage'))->toBeFalse()->and($this->budi->fresh()->can('access.manage'))->toBeFalse();
});

it('supervisor diberi akses dashboard per orang: menu Penjualan muncul hanya untuknya', function () {
    $sari = $this->pos->supervisor;
    $sari->forceFill(['email_verified_at' => now()])->save();
    app(SetEmployeePermissions::class)->handle($this->owner, $sari, [...UserRole::Supervisor->grantedPermissions(), 'dashboard.access']);

    $this->actingAs($sari->fresh());
    CurrentOutlet::set($sari->fresh());
    expect(OrderResource::canViewAny())->toBeTrue();

    $other = User::factory()->supervisor()->forOutlet($this->pos->outlet)->create();
    $this->actingAs($other);
    expect(OrderResource::canViewAny())->toBeFalse();
});

it('UI Karyawan: aksi Hak akses (owner), badge, Riwayat perubahan, & jumlah di halaman Hak akses', function () {
    $page = Livewire::test(ManageEmployees::class)
        ->assertActionVisible(TestAction::make('permissions')->table($this->budi))
        ->assertActionHidden(TestAction::make('permissions')->table($this->owner));

    $page->callAction(TestAction::make('permissions')->table($this->budi), [
        'permissions' => [...AccessSettings::groupState(UserRole::Cashier->grantedPermissions()), 'transactions' => ['order.create', 'order.view_own', 'order.reprint', 'order.discount', 'order.void', 'self_order.handle']],
    ])->assertHasNoActionErrors()->assertNotified('Hak akses khusus disimpan');

    expect($this->budi->fresh()->permissions_granted)->toBe(['order.void']);
    Livewire::test(ManageEmployees::class)->assertSee('Hak akses khusus');

    $log = ActivityLog::query()->where('subject_id', $this->budi->id)->latest('id')->get()
        ->first(fn (ActivityLog $l): bool => isset($l->properties['attributes']['permissions_granted']));
    expect(ActivityLogResource::summary($log))->toContain('Izin tambahan')->toContain('Void transaksi');

    Livewire::test(AccessSettings::class)->assertSee('1 karyawan kasir punya hak akses khusus');
});

it('isolasi tenant: owner tidak bisa mengatur karyawan bisnis lain', function () {
    $other = posSetup();
    TenantContext::set($this->pos->tenantId);

    expect(epFail(fn () => app(SetEmployeePermissions::class)->handle($this->owner, $other->cashier, ['order.void'])))->toBe('FORBIDDEN');
});
