<?php

declare(strict_types=1);

use App\Actions\Cashier\RegisterWebDevice;
use App\Actions\Device\PruneIdleWebDevices;
use App\Actions\Device\SetDeviceRevoked;
use App\Enums\AppPlatform;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/*
 * Device kasir web menganggur (Q117): web, belum dicabut, tanpa shift terbuka, tidak aktif > idle_days hari.
 * Dipangkas `pos:prune-web-devices` (dicabut + device_uid dilepas) atau slotnya dipakai ulang saat batas/kuota penuh.
 */
beforeEach(function () {
    config(['pos.web_cashier.idle_days' => 30]);
    $this->pos = posSetup(openShift: false);
    $this->pos->cashier->forceFill(['web_cashier_access' => true])->save();
});

afterEach(fn () => config(['pos.edition' => 'saas']));

/** Device web di outlet POS; $daysAgo = hari sejak terakhir aktif. */
function webDevice(stdClass $pos, int $daysAgo, ?string $browserId = null): Device
{
    return Device::factory()->forOutlet($pos->outlet)->create([
        'name' => 'Kasir web lama',
        'platform' => AppPlatform::Web,
        'device_uid' => RegisterWebDevice::uid($browserId ?? (string) Str::uuid(), $pos->outlet->id),
        'last_seen_at' => now()->subDays($daysAgo),
    ]);
}

function registerWeb(stdClass $pos, User $user, string $browserId): Device
{
    $user->forgetOutletIds();

    return TenantContext::run($pos->tenantId, fn (): Device => app(RegisterWebDevice::class)->handle($user, $pos->outlet->fresh(), $browserId));
}

function pruneWebDevices(): int
{
    TenantContext::forget();

    return app(PruneIdleWebDevices::class)->handle();
}

describe('pos:prune-web-devices', function () {
    it('mencabut & melepas device web menganggur beserta tokennya; yang lain tidak disentuh', function () {
        $idle = webDevice($this->pos, 31);
        $neverUsed = webDevice($this->pos, 0);
        $neverUsed->forceFill(['last_seen_at' => null, 'created_at' => now()->subDays(40)])->saveQuietly();
        $recent = webDevice($this->pos, 29);
        $tablet = $this->pos->device;
        $tablet->forceFill(['last_seen_at' => now()->subDays(90)])->saveQuietly();
        $ownerRevoked = webDevice($this->pos, 60);
        $ownerRevoked->forceFill(['revoked_at' => now()->subDays(50)])->saveQuietly();
        userToken($this->pos->cashier, $idle);

        $this->artisan('pos:prune-web-devices')->expectsOutputToContain('2 perangkat kasir web menganggur dicabut')->assertSuccessful();

        expect($idle->fresh()->isRevoked())->toBeTrue()
            ->and($idle->fresh()->device_uid)->toBe(Device::RELEASED_UID_PREFIX.$idle->id)
            ->and($idle->fresh()->isReleasedWeb())->toBeTrue()
            ->and(PersonalAccessToken::query()->where('device_id', $idle->id)->count())->toBe(0)
            ->and($neverUsed->fresh()->isRevoked())->toBeTrue()
            ->and($recent->fresh()->isRevoked())->toBeFalse()
            ->and($tablet->fresh()->isRevoked())->toBeFalse()
            // Dicabut owner: device_uid tetap, browser itu tetap diblokir
            ->and($ownerRevoked->fresh()->device_uid)->toStartWith('web:');
    });

    it('idempotent: dijalankan dua kali hasilnya sama', function () {
        $idle = webDevice($this->pos, 31);

        expect(pruneWebDevices())->toBe(1);
        $first = $idle->fresh();
        $this->travel(1)->days();

        expect(pruneWebDevices())->toBe(0)
            ->and($idle->fresh()->revoked_at?->equalTo($first->revoked_at))->toBeTrue()
            ->and($idle->fresh()->device_uid)->toBe($first->device_uid)
            ->and(Device::allTenants()->whereNull('revoked_at')->count())->toBe(1);
    });

    it('shift terbuka aman: device web lama dengan shift terbuka tidak dicabut', function () {
        $busy = webDevice($this->pos, 90);
        $shift = TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($busy, $this->pos->cashier)->create());

        expect(pruneWebDevices())->toBe(0)
            ->and($busy->fresh()->isRevoked())->toBeFalse()
            ->and($shift->fresh()->open_device_key)->toBe($busy->id);
    });

    it('lintas tenant: tiap tenant diproses di konteksnya sendiri', function () {
        $mine = webDevice($this->pos, 31);
        $foreign = posSetup(openShift: false);
        $theirs = webDevice($foreign, 31);
        $theirsRecent = webDevice($foreign, 1);

        expect(pruneWebDevices())->toBe(2)
            ->and($mine->fresh()->isRevoked())->toBeTrue()
            ->and($theirs->fresh()->isRevoked())->toBeTrue()
            ->and($theirs->fresh()->tenant_id)->toBe($foreign->tenantId)
            ->and($theirsRecent->fresh()->isRevoked())->toBeFalse()
            ->and(TenantContext::check())->toBeFalse();
    });
});

describe('daftar kasir web', function () {
    it('browser lama yang device-nya dipangkas mendapat device baru, bukan pesan dicabut', function () {
        $browserId = (string) Str::uuid();
        $old = webDevice($this->pos, 31, $browserId);
        pruneWebDevices();

        $device = registerWeb($this->pos, $this->pos->cashier, $browserId);

        expect($device->id)->not->toBe($old->id)
            ->and($device->isRevoked())->toBeFalse()
            ->and($device->device_uid)->toBe(RegisterWebDevice::uid($browserId, $this->pos->outlet->id));
    });

    it('batas per outlet penuh: device web menganggur tertua dipakai ulang', function () {
        config(['pos.web_cashier.max_devices_per_outlet' => 2]);
        $older = webDevice($this->pos, 45);
        $old = webDevice($this->pos, 31);

        $device = registerWeb($this->pos, $this->pos->cashier, (string) Str::uuid());

        expect($older->fresh()->isReleasedWeb())->toBeTrue()
            ->and($older->fresh()->isRevoked())->toBeTrue()
            ->and($old->fresh()->isRevoked())->toBeFalse()
            ->and(Device::allTenants()->where('platform', AppPlatform::Web)->whereNull('revoked_at')->pluck('id')->sort()->values()->all())
            ->toBe(collect([$old->id, $device->id])->sort()->values()->all());
    });

    it('batas per outlet penuh tanpa yang menganggur (shift terbuka / baru dipakai): tetap ditolak', function () {
        config(['pos.web_cashier.max_devices_per_outlet' => 2]);
        $busy = webDevice($this->pos, 90);
        TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($busy, $this->pos->supervisor)->create());
        $recent = webDevice($this->pos, 2);

        expect(fn () => registerWeb($this->pos, $this->pos->cashier, (string) Str::uuid()))
            ->toThrow(BusinessException::class, 'Batas 2 perangkat kasir web');
        expect($busy->fresh()->isRevoked())->toBeFalse()
            ->and($recent->fresh()->isRevoked())->toBeFalse()
            ->and(Device::allTenants()->where('platform', AppPlatform::Web)->count())->toBe(2);
    });

    it('kuota perangkat paket penuh: device web menganggur dipakai ulang; tetap lewat batas = ditolak tanpa mencabut', function () {
        $idle = webDevice($this->pos, 31);
        $tenant = Tenant::query()->findOrFail($this->pos->tenantId);
        $tenant->forceFill(['limit_overrides' => ['devices' => 2]])->save();

        $device = registerWeb($this->pos, $this->pos->cashier, (string) Str::uuid());
        expect($idle->fresh()->isRevoked())->toBeTrue()
            ->and($device->isRevoked())->toBeFalse();

        // Lewat batas setelah downgrade (3 aktif, batas 1): melepas satu tidak cukup → ditolak & pelepasan dibatalkan
        $idle2 = webDevice($this->pos, 31);
        $tenant->forceFill(['limit_overrides' => ['devices' => 1]])->save();

        try {
            registerWeb($this->pos, $this->pos->cashier, (string) Str::uuid());
            $this->fail('Seharusnya PLAN_LIMIT_REACHED');
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe('PLAN_LIMIT_REACHED');
        }
        expect($idle2->fresh()->isRevoked())->toBeFalse();
    });

    it('isolasi tenant: kuota penuh tidak memakai ulang device menganggur tenant lain', function () {
        $foreign = posSetup(openShift: false);
        $theirs = webDevice($foreign, 90);
        Tenant::query()->findOrFail($this->pos->tenantId)->forceFill(['limit_overrides' => ['devices' => 1]])->save();

        expect(fn () => registerWeb($this->pos, $this->pos->cashier, (string) Str::uuid()))
            ->toThrow(BusinessException::class);
        expect($theirs->fresh()->isRevoked())->toBeFalse();
    });
});

it('device yang dilepas otomatis tidak bisa diaktifkan lagi', function () {
    $idle = webDevice($this->pos, 31);
    pruneWebDevices();

    expect(fn () => TenantContext::run($this->pos->tenantId, fn () => app(SetDeviceRevoked::class)->handle($idle->fresh(), false)))
        ->toThrow(BusinessException::class, 'dilepas otomatis');
    expect($idle->fresh()->isRevoked())->toBeTrue();
});
