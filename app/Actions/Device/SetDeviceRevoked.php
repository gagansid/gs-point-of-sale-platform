<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Cabut / aktifkan lagi perangkat (tablet atau browser kasir web, Q54). Dicabut: semua token
 * perangkat & token user yang terikat perangkat itu langsung tidak berlaku; sesi kasir web
 * diarahkan ke pilih outlet. Perangkat dengan shift terbuka harus ditutup shift-nya dulu.
 */
final class SetDeviceRevoked
{
    /** @throws BusinessException */
    public function handle(Device $device, bool $revoked): Device
    {
        return DB::transaction(function () use ($device, $revoked): Device {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);

            if (! $revoked) {
                $locked->forceFill(['revoked_at' => null])->save();

                return $locked;
            }

            if (Shift::query()->where('open_device_key', $locked->id)->exists()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Perangkat ini masih punya shift terbuka. Tutup shift dulu (bisa tutup paksa dari aplikasi/kasir web).');
            }

            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now()])->save();
            }

            $locked->tokens()->delete();
            PersonalAccessToken::query()->where('device_id', $locked->id)->delete();

            return $locked;
        });
    }
}
