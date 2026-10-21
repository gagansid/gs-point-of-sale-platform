<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\BusinessType;
use App\Enums\TenantAccess;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Concerns\RecordsAuthor;
use App\Models\Scopes\TenantScope;
use App\Support\Edition;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property array<string, int>|null $limit_overrides batas khusus dari admin per kuota (ADR 0025); menimpa batas paket
 * @property string|null $plan_id paket langganan (ADR 0024); null = semua fitur
 * @property BillingCycle|null $billing_cycle
 * @property string|null $next_plan_id paket yang dijadwalkan berlaku di plan_changes_at (ADR 0024 revisi)
 * @property BillingCycle|null $next_billing_cycle
 * @property CarbonImmutable|null $plan_changes_at
 * @property array<string, mixed>|null $preferences preferensi owner (Q128), lewat App\Support\TenantPreferences
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, RecordsAuthor, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'business_type',
        'status',
        'subscription_ends_at',
        'limit_overrides',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => TenantStatus::class,
            'subscription_ends_at' => 'immutable_datetime',
            'limit_overrides' => 'array',
            'preferences' => 'array',
            'billing_cycle' => BillingCycle::class,
            'next_billing_cycle' => BillingCycle::class,
            'plan_changes_at' => 'immutable_datetime',
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
            // Jual putus tidak mengenal trial/langganan: hanya penangguhan yang membatasi
            Edition::isSaas() && $this->subscription_ends_at !== null && ! $this->subscription_ends_at->isFuture() => TenantAccess::ReadOnly,
            default => TenantAccess::Full,
        };
    }

    /**
     * Batas kuota yang berlaku (ADR 0025): batas khusus admin, lalu batas paket; null = tanpa batas. Edisi jual putus
     * tidak dibatasi.
     */
    public function limit(string $quota): ?int
    {
        if (Edition::isSelfHosted()) {
            return null;
        }

        $override = $this->limit_overrides[$quota] ?? null;
        if ($override !== null) {
            return (int) $override;
        }

        if ($this->plan_id === null) {
            return null;
        }

        $base = $this->plan?->limit($quota);
        if ($base === null) {
            return null;
        }

        // Add-on kuota menambah batas paket (paket tanpa batas tetap tanpa batas)
        return $base + (int) $this->activeAddons()
            ->filter(fn (TenantAddon $a): bool => $a->addon->isQuota() && $a->addon->quota_key === $quota)
            ->sum(fn (TenantAddon $a): int => $a->quantity * (int) $a->addon->quota_amount);
    }

    /**
     * Batas efektif semua kuota master data (null = tanpa batas).
     *
     * @return array<string, int|null>
     */
    public function limits(): array
    {
        return array_map(fn (string $key): ?int => $this->limit($key), array_combine(array_keys(Quota::options()), array_keys(Quota::options())));
    }

    /** Batas outlet aktif yang berlaku (ADR 0010, kuota "outlets" ADR 0025). */
    public function outletLimit(): ?int
    {
        return $this->limit('outlets');
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
        if (Edition::isSelfHosted() || $this->status !== TenantStatus::Trial || $this->subscription_ends_at === null || ! $this->subscription_ends_at->isFuture()) {
            return null;
        }

        return (int) ceil(now()->diffInSeconds($this->subscription_ends_at) / 86400);
    }

    /*
     * Relasi dari tenant sudah dibatasi oleh foreign key tenant_id, sehingga TenantScope dilepas
     * agar panel /admin (tanpa tenant context) bisa menghitung & menampilkan datanya.
     */

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'next_plan_id');
    }

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

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Add-on bisnis (ADR 0025): `active` & `cancelling` sama-sama masih berlaku sampai dilepas.
     *
     * @return HasMany<TenantAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(TenantAddon::class)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Add-on yang berlaku beserta data add-on (dimuat sekali per objek tenant).
     *
     * @return Collection<int, TenantAddon>
     */
    public function activeAddons(): Collection
    {
        if (! $this->relationLoaded('addons')) {
            $this->load('addons');
        }
        // refresh() memuat ulang relasi tingkat atas saja: pastikan data add-on ikut dimuat
        $this->addons->loadMissing('addon');

        return $this->addons->filter(fn (TenantAddon $a): bool => $a->addon !== null)->values();
    }

    /** @return HasMany<SubscriptionInvoice, $this> */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Data platform: pembuat/pengubah = super admin (RecordsAuthor).
     *
     * @return class-string<Admin>
     */
    public static function authorModel(): string
    {
        return Admin::class;
    }
}
