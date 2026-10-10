<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\TenantAccess;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Scopes\TenantScope;
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
    /**
     * Tingkat akses (ADR 0009): ditangguhkan = diblokir; masa trial/langganan habis = hanya-baca.
     */
    public function access(): TenantAccess
    {
        return match (true) {
            $this->status === TenantStatus::Suspended => TenantAccess::Blocked,
            $this->subscription_ends_at !== null && ! $this->subscription_ends_at->isFuture() => TenantAccess::ReadOnly,
            default => TenantAccess::Full,
        };
    }

    /** Boleh login & melihat data (tidak diblokir). Hanya-baca tetap boleh login. */
    public function isActive(): bool
    {
        return $this->access() !== TenantAccess::Blocked;
    }

    /** Trial/langganan habis: perubahan data ditolak SUBSCRIPTION_EXPIRED. */
    public function isReadOnly(): bool
    {
        return $this->access() === TenantAccess::ReadOnly;
    }

    /**
     * Owner hasil daftar mandiri belum memverifikasi email (ADR 0009, SPEC Q31): checkout & pembayaran
     * ditolak. Benar bila ada owner aktif belum terverifikasi dan belum ada satu pun yang terverifikasi.
     */
    public function needsOwnerEmailVerification(): bool
    {
        $owners = User::allTenants()
            ->where('tenant_id', $this->id)
            ->where('role', UserRole::Owner)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->selectRaw('COUNT(email_verified_at) AS verified, COUNT(*) AS total')
            ->toBase()
            ->first();

        return (int) ($owners->total ?? 0) > 0 && (int) ($owners->verified ?? 0) === 0;
    }

    /** Sisa hari trial yang masih berjalan (dibulatkan ke atas), null bila bukan trial berjalan. */
    public function trialDaysLeft(): ?int
    {
        if ($this->status !== TenantStatus::Trial || $this->subscription_ends_at === null || ! $this->subscription_ends_at->isFuture()) {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($this->subscription_ends_at) / 86400);
    }

    /*
     * Relasi dari tenant sudah dibatasi oleh foreign key tenant_id, sehingga TenantScope dilepas
     * agar panel /admin (tanpa tenant context) bisa menghitung & menampilkan datanya.
     */

    /** @return HasMany<Outlet, $this> */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class)->withoutGlobalScope(TenantScope::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class)->withoutGlobalScope(TenantScope::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class)->withoutGlobalScope(TenantScope::class);
    }
}
