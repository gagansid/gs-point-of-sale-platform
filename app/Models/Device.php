<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppPlatform;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laravel\Sanctum\HasApiTokens;

/**
 * Perangkat kasir terdaftar. Punya token Sanctum sendiri (ability "device", ADR 0002).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $name
 * @property string $device_uid
 * @property AppPlatform|null $platform
 * @property string|null $app_version
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToOutlet, BelongsToTenant, HasApiTokens, HasFactory, HasUuids;

    /** device_uid device web yang dipangkas/dipakai ulang: "web-released:{id}" (Q116). */
    public const RELEASED_UID_PREFIX = 'web-released:';

    protected $fillable = [
        'outlet_id',
        'name',
        'device_uid',
        'platform',
        'app_version',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => AppPlatform::class,
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Shift yang sedang terbuka di perangkat ini (paling banyak satu, `open_device_key` unik).
     *
     * @return HasOne<Shift, $this>
     */
    public function openShift(): HasOne
    {
        return $this->hasOne(Shift::class, 'open_device_key');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Batas "menganggur" device web (Q116): aktivitas terakhir sebelum waktu ini. */
    public static function idleCutoff(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subDays(max(1, (int) config('pos.web_cashier.idle_days')));
    }

    /**
     * Device web menganggur (Q116): belum dicabut, tanpa shift terbuka, aktivitas terakhir (`last_seen_at`, atau
     * `created_at` bila belum pernah dipakai) sebelum $cutoff.
     *
     * @param  Builder<self>  $query
     */
    public function scopeIdleWeb(Builder $query, CarbonImmutable $cutoff): void
    {
        $query->where('platform', AppPlatform::Web)->whereNull('revoked_at')
            ->whereRaw('COALESCE(last_seen_at, created_at) < ?', [$cutoff])
            ->whereNotExists(fn (QueryBuilder $shift) => $shift->selectRaw('1')->from('shifts')->whereColumn('shifts.open_device_key', 'devices.id'));
    }

    /** Sama dengan scopeIdleWeb untuk satu record; $hasOpenShift dari pemanggil (daftar perangkat sudah memuatnya). */
    public function isIdleWeb(bool $hasOpenShift, ?CarbonImmutable $cutoff = null): bool
    {
        $lastActive = $this->last_seen_at ?? $this->created_at;

        return $this->platform === AppPlatform::Web && ! $this->isRevoked() && ! $hasOpenShift
            && $lastActive !== null && $lastActive->lt($cutoff ?? self::idleCutoff());
    }

    /** Device web yang dilepas otomatis (Q116): device_uid bukan lagi milik browser mana pun. */
    public function isReleasedWeb(): bool
    {
        return $this->platform === AppPlatform::Web && str_starts_with($this->device_uid, self::RELEASED_UID_PREFIX);
    }

    public function outletIsActive(): bool
    {
        return Outlet::allTenants()->whereKey($this->outlet_id)->where('is_active', true)->exists();
    }
}
