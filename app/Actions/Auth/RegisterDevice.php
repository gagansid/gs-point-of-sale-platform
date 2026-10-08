<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AppPlatform;
use App\Models\Device;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mendaftarkan (atau mendaftarkan ulang) device kasir ke outlet dan menerbitkan device token
 * (ADR 0002). Token lama device dicabut: hanya satu device token aktif per device.
 */
final class RegisterDevice
{
    /**
     * @return array{device: Device, token: string}
     */
    public function handle(Outlet $outlet, string $name, string $deviceUid, ?AppPlatform $platform, ?string $appVersion): array
    {
        return DB::transaction(function () use ($outlet, $name, $deviceUid, $platform, $appVersion): array {
            $device = Device::query()->firstOrNew(['device_uid' => $deviceUid]);
            $wasRevoked = $device->exists && $device->isRevoked();

            $device->fill([
                'outlet_id' => $outlet->id,
                'name' => $name,
                'platform' => $platform,
                'app_version' => $appVersion,
                'last_seen_at' => now(),
            ]);
            // Mendaftarkan ulang device yang pernah dicabut adalah keputusan sadar owner
            $device->revoked_at = null;
            $device->save();

            $device->tokens()->delete();
            $token = $device->createToken('device', ['device'])->plainTextToken;

            Log::info('Device didaftarkan', ['device_id' => $device->id, 're_registered' => $wasRevoked]);

            return ['device' => $device, 'token' => $token];
        });
    }
}
