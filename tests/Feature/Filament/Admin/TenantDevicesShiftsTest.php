<?php

declare(strict_types=1);

use App\Enums\AppPlatform;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Admin\Resources\Tenants\RelationManagers\DevicesRelationManager;
use App\Filament\Admin\Resources\Tenants\RelationManagers\OpenShiftsRelationManager;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\StaleShiftsWidget;
use App\Models\Admin;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\DashboardCache;
use App\Services\Admin\PlatformActivity;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Visibilitas support di /admin (Q116): perangkat & shift terbuka di detail tenant (hanya baca) dan
 * "Shift terbuka > 24 jam" lintas bisnis di Dasbor.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 03:00:00');
    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->admin = Admin::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin, 'admin');

    $this->outlet = Outlet::factory()->create(['name' => 'Outlet Braga']);
    $this->tenant = Tenant::query()->findOrFail($this->outlet->tenant_id);
    $this->tablet = Device::factory()->forOutlet($this->outlet)->create(['name' => 'Tablet Depan', 'platform' => AppPlatform::Android, 'app_version' => '1.4.2']);
    $this->browser = Device::factory()->forOutlet($this->outlet)->create(['name' => 'Browser Kasir', 'platform' => AppPlatform::Web, 'app_version' => null]);
    $this->old = Device::factory()->forOutlet($this->outlet)->revoked()->create(['name' => 'Tablet Lama']);
    $this->cashier = User::factory()->cashier()->forOutlet($this->outlet)->create(['name' => 'Rina']);

    // Shift kasir web tertinggal 3 hari, shift tablet baru dibuka, shift lama sudah ditutup
    $this->staleShift = Shift::factory()->forDevice($this->browser, $this->cashier)->create(['opened_at' => now()->subDays(3)]);
    $this->freshShift = Shift::factory()->forDevice($this->tablet, $this->cashier)->create(['opened_at' => now()->subHours(2)]);
    $this->closedShift = Shift::factory()->forDevice($this->old, $this->cashier)->closed()->create(['opened_at' => now()->subDays(5)]);

    // Bisnis lain: tidak boleh muncul di detail tenant di atas
    $otherOutlet = Outlet::factory()->create(['name' => 'Outlet Lain']);
    $this->otherDevice = Device::factory()->forOutlet($otherOutlet)->create(['name' => 'Kasir Rahasia']);
    $this->otherShift = Shift::factory()->forDevice($this->otherDevice)->create(['opened_at' => now()->subDays(2)]);

    DashboardCache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('detail tenant', function () {
    it('halaman detail memuat tab perangkat & shift terbuka', function () {
        $this->get(TenantResource::getUrl('view', ['record' => $this->tenant]))
            ->assertOk()
            // Dua relation manager = tab; tab pertama (Perangkat) aktif
            ->assertSeeLivewire(DevicesRelationManager::class)
            ->assertSee(['Perangkat', 'Shift terbuka']);
    });

    it('perangkat: outlet, jenis, versi, status dicabut, shift terbuka; tanpa perangkat bisnis lain', function () {
        Livewire::test(DevicesRelationManager::class, ['ownerRecord' => $this->tenant, 'pageClass' => ViewTenant::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->tablet, $this->browser, $this->old])
            ->assertCanNotSeeTableRecords([$this->otherDevice])
            ->assertSee(['Outlet Braga', 'Android', 'Kasir web', '1.4.2', 'Dicabut', 'Aktif'])
            ->assertDontSee(['Kasir Rahasia', 'Outlet Lain'])
            ->assertTableColumnStateSet('open_shift', 'Ada', $this->browser)
            ->assertTableColumnStateSet('open_shift', 'Ada', $this->tablet)
            // Shift perangkat lama sudah ditutup
            ->assertTableColumnStateSet('open_shift', 'Tidak', $this->old);
    });

    it('shift terbuka: hanya status terbuka milik tenant ini, merah bila > 24 jam', function () {
        Livewire::test(OpenShiftsRelationManager::class, ['ownerRecord' => $this->tenant, 'pageClass' => ViewTenant::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->staleShift, $this->freshShift], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->closedShift, $this->otherShift])
            ->assertSee(['Outlet Braga', 'Browser Kasir', 'Tablet Depan', 'Rina', 'Lebih dari 24 jam'])
            ->assertDontSee('Kasir Rahasia');
    });

    it('hanya baca: tanpa aksi, tidak tampil di form Ubah, shift tidak berubah', function () {
        expect(DevicesRelationManager::canViewForRecord($this->tenant, ViewTenant::class))->toBeTrue()
            ->and(DevicesRelationManager::canViewForRecord($this->tenant, EditTenant::class))->toBeFalse()
            ->and(OpenShiftsRelationManager::canViewForRecord($this->tenant, EditTenant::class))->toBeFalse();

        $component = Livewire::test(OpenShiftsRelationManager::class, ['ownerRecord' => $this->tenant, 'pageClass' => ViewTenant::class]);
        $table = $component->instance()->getTable();
        expect($component->instance()->isReadOnly())->toBeTrue()
            ->and($table->getHeaderActions())->toBeEmpty()
            ->and($table->getRecordActions())->toBeEmpty()
            ->and($table->getToolbarActions())->toBeEmpty()
            ->and($this->staleShift->fresh()?->isOpen())->toBeTrue();
    });

    it('state kosong: bisnis tanpa perangkat & shift', function () {
        $empty = Tenant::factory()->create();

        Livewire::test(DevicesRelationManager::class, ['ownerRecord' => $empty, 'pageClass' => ViewTenant::class])
            ->assertCountTableRecords(0)->assertSee('Belum ada perangkat');
        Livewire::test(OpenShiftsRelationManager::class, ['ownerRecord' => $empty, 'pageClass' => ViewTenant::class])
            ->assertCountTableRecords(0)->assertSee('Tidak ada shift terbuka');
    });

    it('user tenant tidak bisa membuka detail tenant /admin', function () {
        auth('admin')->logout();
        $owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($owner, 'web')
            ->get(TenantResource::getUrl('view', ['record' => $this->tenant]))
            ->assertRedirect('/admin/login');
    });
});

describe('dasbor: shift terbuka > 24 jam', function () {
    it('lintas bisnis, terlama dulu, tanpa shift baru/tertutup/bisnis terhapus, bertaut ke tenant', function () {
        $deleted = Outlet::factory()->create();
        Shift::factory()->forDevice(Device::factory()->forOutlet($deleted)->create())->create(['opened_at' => now()->subDays(4)]);
        Tenant::query()->findOrFail($deleted->tenant_id)->delete();

        $stale = app(PlatformActivity::class)->staleShifts();

        expect($stale['count'])->toBe(2)
            ->and(array_column($stale['rows'], 'shift_id'))->toBe([$this->staleShift->id, $this->otherShift->id])
            ->and($stale['rows'][0])->toMatchArray([
                'tenant_id' => $this->tenant->id,
                'tenant_name' => $this->tenant->name,
                'outlet_name' => 'Outlet Braga',
                'device_name' => 'Browser Kasir',
            ]);

        Livewire::test(StaleShiftsWidget::class)
            ->assertOk()
            ->assertSee(['Shift terbuka > 24 jam (2)', $this->tenant->name, 'Outlet Braga · Browser Kasir'])
            ->assertSee(TenantResource::getUrl('view', ['record' => $this->tenant->id]), escape: false);
    });

    it('memakai DashboardCache: angka baru setelah Muat ulang data', function () {
        expect(app(PlatformActivity::class)->staleShifts()['count'])->toBe(2);

        $this->freshShift->forceFill(['opened_at' => now()->subDays(2)])->save();
        expect(app(PlatformActivity::class)->staleShifts()['count'])->toBe(2);

        DashboardCache::flush();
        expect(app(PlatformActivity::class)->staleShifts()['count'])->toBe(3);
    });

    it('state kosong', function () {
        Shift::allTenants()->update(['status' => 'closed', 'open_device_key' => null, 'closed_at' => now()]);
        DashboardCache::flush();

        Livewire::test(StaleShiftsWidget::class)
            ->assertOk()
            ->assertSee('Shift terbuka > 24 jam (0)')
            ->assertSee('Tidak ada shift tertinggal');
    });
});
