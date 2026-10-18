<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pangkas device kasir web menganggur (Q117): browser tanpa shift terbuka yang tidak aktif lebih dari
 * `pos.web_cashier.idle_days` hari dicabut otomatis agar tidak menghabiskan kuota perangkat (ADR 0025) dan batas
 * device web per outlet. Device "dilepas": `device_uid` diganti sehingga browser lama yang kembali mendapat device
 * baru (bukan pesan "dicabut owner"); riwayat shift & transaksi tetap pada record lama. Command
 * `pos:prune-web-devices` harian; aman diulang. Shift terbuka tidak pernah disentuh.
 */
final class PruneIdleWebDevices
{
    /** @return int jumlah device yang dicabut */
    public function handle(?CarbonImmutable $now = null): int
    {
        $cutoff = Device::idleCutoff($now);
        $count = 0;

        // Lintas tenant: tiap device diproses di konteks tenant-nya sendiri
        $candidates = Device::allTenants()->idleWeb($cutoff)->reorder()->select(['id', 'tenant_id'])->lazyById(200);

        foreach ($candidates as $device) {
            $count += TenantContext::run($device->tenant_id, fn (): int => self::release($device->id, $cutoff) ? 1 : 0);
        }

        return $count;
    }

    /**
     * Cabut & lepas satu device web bila (masih) menganggur — dicek ulang dengan baris terkunci, sehingga device yang
     * baru dipakai atau sudah membuka shift sejak dipilih tidak ikut dicabut. Dipakai juga RegisterWebDevice untuk
     * memakai ulang slot device tertua saat kuota/batas penuh.
     */
    public static function release(string $deviceId, CarbonImmutable $cutoff): bool
    {
        return DB::transaction(function () use ($deviceId, $cutoff): bool {
            $device = Device::query()->idleWeb($cutoff)->lockForUpdate()->find($deviceId);

            if ($device === null) {
                return false;
            }

            $device->forceFill(['revoked_at' => now(), 'device_uid' => Device::RELEASED_UID_PREFIX.$device->id])->save();

            $device->tokens()->delete();
            PersonalAccessToken::query()->where('device_id', $device->id)->delete();

            return true;
        });
    }
}
