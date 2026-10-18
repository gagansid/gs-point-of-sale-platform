<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Enums\AppPlatform;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Scopes\OutletScope;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Models\User;
use App\Support\Quotas;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Browser kasir web sebagai device (Q54): satu device per (browser, outlet), dibuat otomatis saat
 * outlet dipilih. Shift & transaksi terikat device seperti tablet. Device yang dicabut owner
 * tidak diaktifkan lagi dari sini (berbeda dengan daftar ulang tablet oleh owner).
 *
 * Cookie browser hilang (browser ditutup di mode privat, cookie dihapus, browser/perangkat lain):
 * shift web yang masih terbuka dan sedang dipegang user ini di outlet yang sama dilanjutkan, bukan
 * membuat device baru tanpa shift. Pemanggil menulis ulang cookie ke browser ID device itu.
 */
final class RegisterWebDevice
{
    /**
     * @param  string  $browserId  UUID acak dari cookie browser
     *
     * @throws BusinessException
     */
    public function handle(User $user, Outlet $outlet, string $browserId): Device
    {
        if (! $outlet->is_active || ! $user->canAccessOutlet($outlet->id)) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Anda tidak ditugaskan di outlet ini');
        }

        $uid = self::uid($browserId, $outlet->id);
        $device = Device::forTenant($outlet->tenant_id)->where('device_uid', $uid)->first();

        if ($device === null || ! self::hasOpenShift($device)) {
            $device = self::resumableDevice($user, $outlet) ?? $device;
        }

        if ($device === null) {
            // Cookie browser bisa dihapus untuk membuat device baru: batasi jumlah device web aktif per outlet
            $max = (int) config('pos.web_cashier.max_devices_per_outlet');
            $active = Device::forTenant($outlet->tenant_id)->where('outlet_id', $outlet->id)
                ->where('platform', AppPlatform::Web)->whereNull('revoked_at')->count();

            if ($active >= $max) {
                throw BusinessException::of(ErrorCode::Forbidden, "Batas {$max} perangkat kasir web di outlet ini tercapai. Minta owner mencabut perangkat lama di menu Perangkat.");
            }

            try {
                $device = DB::transaction(function () use ($outlet, $browserId, $uid): Device {
                    // Kuota perangkat paket (ADR 0025), dihitung bersama perangkat aplikasi Android
                    Quotas::ensureAvailable($outlet->tenant_id, Quotas::DEVICES);

                    return Device::query()->create([
                        'outlet_id' => $outlet->id,
                        'name' => 'Kasir web '.mb_substr($browserId, 0, 4),
                        'device_uid' => $uid,
                        'platform' => AppPlatform::Web,
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                // Dua tab membuat bersamaan: pakai yang sudah ada
                $device = Device::forTenant($outlet->tenant_id)->where('device_uid', $uid)->firstOrFail();
            }
        }

        if ($device->isRevoked()) {
            throw BusinessException::of(ErrorCode::DeviceNotRegistered, 'Browser ini sudah dicabut owner dari daftar perangkat');
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $device;
    }

    /** Browser ID (cookie) dari device_uid "web:{browser}:{outlet}". */
    public static function browserIdOf(Device $device): ?string
    {
        $parts = explode(':', $device->device_uid);

        return count($parts) === 3 && $parts[0] === 'web' ? $parts[1] : null;
    }

    private static function hasOpenShift(Device $device): bool
    {
        return Shift::query()->withoutGlobalScope(OutletScope::class)->where('open_device_key', $device->id)->exists();
    }

    /**
     * Device web outlet ini yang shift terbukanya sedang dipegang $user: operator aktif terakhir = $user,
     * atau pembuka shift bila log operator belum ada. Shift yang sudah diambil alih kasir lain tidak dilanjutkan.
     */
    private static function resumableDevice(User $user, Outlet $outlet): ?Device
    {
        // Outlet belum dipilih: konteks outlet request ini belum tentu outlet tujuan
        $shifts = Shift::query()->withoutGlobalScope(OutletScope::class)->where('outlet_id', $outlet->id)->whereNotNull('open_device_key')
            ->whereHas('device', fn ($q) => $q->where('platform', AppPlatform::Web)->whereNull('revoked_at'))
            ->latest('opened_at')->get();

        foreach ($shifts as $shift) {
            $operator = ShiftOperator::query()->where('shift_id', $shift->id)->whereNull('ended_at')->latest('started_at')->value('user_id');

            if (($operator ?? $shift->opened_by) === $user->id) {
                return Device::forTenant($outlet->tenant_id)->find($shift->open_device_key);
            }
        }

        return null;
    }

    /** device_uid "web:{browser}:{outlet}" (maks. 77 karakter, kolom 100). */
    public static function uid(string $browserId, string $outletId): string
    {
        return "web:{$browserId}:{$outletId}";
    }
}
