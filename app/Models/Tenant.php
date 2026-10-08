<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bisnis pelanggan. Bukan model "milik tenant", sehingga tidak memakai BelongsToTenant;
 * hanya dikelola dari panel /admin.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property BusinessType $business_type
 * @property TenantStatus $status
 * @property CarbonImmutable|null $subscription_ends_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'business_type',
        'status',
        'subscription_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => TenantStatus::class,
            'subscription_ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * Boleh memakai sistem: bukan suspended dan langganan/trial belum berakhir.
     * Dipakai middleware EnsureTenantActive (403 TENANT_SUSPENDED).
     */
    public function isActive(): bool
    {
        if ($this->status === TenantStatus::Suspended) {
            return false;
        }

        return $this->subscription_ends_at === null || $this->subscription_ends_at->isFuture();
    }

    /** @return HasMany<Outlet, $this> */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
