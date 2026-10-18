<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantAddon;

/**
 * Fitur yang boleh dipakai tenant aktif menurut paket langganan + add-on fitur (ADR 0024, ADR 0025). Katalog = master data `features`
 * (key). Jual putus, tanpa konteks tenant, atau tenant tanpa paket = semua fitur. Dipakai menu dashboard, middleware
 * API feature:{key}, dan mesin (promo, kasbon, self-order, member, voucher).
 */
final class Features
{
    /** @var array<string, list<string>|null> fitur paket per tenant per request; null = semua fitur */
    private static array $cache = [];

    public static function enabled(string $feature, ?Tenant $tenant = null): bool
    {
        $features = self::planFeatures($tenant);

        return $features === null || in_array($feature, $features, true);
    }

    /**
     * @throws BusinessException FEATURE_NOT_AVAILABLE
     */
    public static function ensure(string $feature, ?Tenant $tenant = null): void
    {
        if (! self::enabled($feature, $tenant)) {
            throw BusinessException::of(ErrorCode::FeatureNotAvailable, details: ['feature' => $feature]);
        }
    }

    /**
     * Key fitur aktif untuk tenant (auth/me `tenant.features`): hanya key yang ada di katalog, urut katalog.
     *
     * @return list<string>
     */
    public static function enabledKeys(?Tenant $tenant = null): array
    {
        $catalog = array_keys(Feature::options());
        $features = self::planFeatures($tenant);

        return $features === null ? $catalog : array_values(array_intersect($catalog, $features));
    }

    /** Lupakan cache (setelah paket tenant berubah, dan antar test). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return list<string>|null null = semua fitur */
    private static function planFeatures(?Tenant $tenant): ?array
    {
        if (Edition::isSelfHosted()) {
            return null;
        }

        $tenantId = $tenant->id ?? TenantContext::id();
        if ($tenantId === null) {
            return null;
        }

        if (! array_key_exists($tenantId, self::$cache)) {
            $tenant ??= Tenant::query()->find($tenantId);
            $plan = $tenant?->plan_id !== null ? Plan::query()->find($tenant->plan_id) : null;
            self::$cache[$tenantId] = $tenant?->plan_id === null ? null : array_values(array_unique([
                ...($plan instanceof Plan ? $plan->features : []),
                // Add-on fitur (ADR 0025 C1)
                ...$tenant->activeAddons()->reject(fn (TenantAddon $a): bool => $a->addon->isQuota())->map(fn (TenantAddon $a): string => (string) $a->addon->feature_key)->all(),
            ]));
        }

        return self::$cache[$tenantId];
    }
}
