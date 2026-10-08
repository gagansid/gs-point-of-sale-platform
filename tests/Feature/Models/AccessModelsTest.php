<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AppVersion;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

describe('tenant', function () {
    it('aktif jika bukan suspended dan langganan belum berakhir', function (Tenant $tenant, bool $active) {
        expect($tenant->isActive())->toBe($active);
    })->with([
        'aktif' => fn () => Tenant::factory()->make(),
        'trial' => fn () => Tenant::factory()->trial()->make(),
        'tanpa batas' => fn () => Tenant::factory()->make(['subscription_ends_at' => null]),
    ])->with([true]);

    it('tidak aktif jika suspended atau langganan habis', function (Tenant $tenant) {
        expect($tenant->isActive())->toBeFalse();
    })->with([
        'suspended' => fn () => Tenant::factory()->suspended()->make(),
        'kedaluwarsa' => fn () => Tenant::factory()->expired()->make(),
    ]);
});

describe('outlet', function () {
    it('memakai tanggal lokal outlet, bukan tanggal UTC (ADR 0001)', function () {
        $outlet = Outlet::factory()->make(['timezone' => 'Asia/Jakarta']);

        // 23.30 WIB masih tanggal 8; 00.30 WIB sudah tanggal 9 walau UTC masih tanggal 8
        expect($outlet->localDate(Carbon::parse('2026-10-08 16:30:00', 'UTC')))->toBe('2026-10-08')
            ->and($outlet->localDate(Carbon::parse('2026-10-08 17:30:00', 'UTC')))->toBe('2026-10-09');

        $makassar = Outlet::factory()->make(['timezone' => 'Asia/Makassar']);
        expect($makassar->localDate(Carbon::parse('2026-10-08 16:30:00', 'UTC')))->toBe('2026-10-09');
    });

    it('kode outlet unik dalam satu tenant, boleh sama di tenant lain', function () {
        $a = Outlet::factory()->create(['code' => 'JKT01']);
        Outlet::factory()->create(['code' => 'JKT01']);

        expect(Outlet::allTenants()->where('code', 'JKT01')->count())->toBe(2);

        TenantContext::run($a->tenant_id, fn () => Outlet::factory()->create(['code' => 'JKT01']));
    })->throws(QueryException::class);
});

describe('user', function () {
    it('menyimpan password & PIN sebagai hash dan tidak pernah mengeluarkannya', function () {
        $user = User::factory()->create();

        expect(Hash::check('123456', $user->pin))->toBeTrue()
            ->and($user->pin)->not->toBe('123456')
            ->and($user->toArray())->not->toHaveKeys(['password', 'pin', 'remember_token']);
    });

    it('tenant_id tidak bisa diisi lewat mass assignment', function () {
        $tenant = Tenant::factory()->create();

        TenantContext::run($tenant->id, fn () => User::query()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'name' => 'Penyusup',
            'role' => UserRole::Owner,
        ]));
    })->throws(MassAssignmentException::class);

    it('permission berasal dari role yang tersimpan di database', function () {
        $supervisor = User::factory()->supervisor()->create();

        expect($supervisor->fresh()?->can('order.void'))->toBeTrue()
            ->and(User::factory()->cashier()->create()->can('order.void'))->toBeFalse();
    });

    it('karyawan tenant lain tidak terlihat', function () {
        $mine = User::factory()->create();
        User::factory()->count(2)->create();

        TenantContext::set($mine->tenant_id);

        expect(User::query()->pluck('id')->all())->toBe([$mine->id]);
    });

    it('email unik di seluruh sistem karena login tidak menyebut tenant', function () {
        User::factory()->create(['email' => 'kasir@contoh.test']);
        User::factory()->create(['email' => 'kasir@contoh.test']);
    })->throws(QueryException::class);
});

describe('device', function () {
    it('selalu milik tenant yang sama dengan outlet-nya', function () {
        $device = Device::factory()->create();

        expect($device->tenant_id)->toBe(Outlet::allTenants()->find($device->outlet_id)?->tenant_id);
    });

    it('device_uid unik dalam satu tenant', function () {
        $outlet = Outlet::factory()->create();
        Device::factory()->forOutlet($outlet)->create(['device_uid' => 'abc']);
        Device::factory()->create(['device_uid' => 'abc']);

        Device::factory()->forOutlet($outlet)->create(['device_uid' => 'abc']);
    })->throws(QueryException::class);
});

describe('data sistem', function () {
    it('versi app di bawah min_version wajib update', function () {
        $version = AppVersion::factory()->make(['min_version' => '1.2.0']);

        expect($version->requiresUpdate('1.1.9'))->toBeTrue()
            ->and($version->requiresUpdate('1.2.0'))->toBeFalse()
            ->and($version->requiresUpdate('1.10.0'))->toBeFalse();
    });

    it('hanya pengumuman yang sedang tayang yang aktif', function () {
        $live = Announcement::factory()->create();
        Announcement::factory()->expired()->create();
        Announcement::factory()->scheduled()->create();

        expect(Announcement::query()->active()->pluck('id')->all())->toBe([$live->id]);
    });
});
