<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppPlatform;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 */
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToOutlet, BelongsToTenant, HasApiTokens, HasFactory, HasUuids;

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
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function outletIsActive(): bool
    {
        return Outlet::allTenants()->whereKey($this->outlet_id)->where('is_active', true)->exists();
    }
}
