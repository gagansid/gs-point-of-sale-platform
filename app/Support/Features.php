<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Fitur yang boleh dipakai tenant aktif menurut paket langganan (ADR 0024, ADR 0025). Katalog = master data `features`
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
            $planId = $tenant !== null ? $tenant->plan_id : Tenant::query()->whereKey($tenantId)->value('plan_id');
            $plan = $planId !== null ? Plan::query()->find($planId) : null;
            self::$cache[$tenantId] = $planId === null ? null : ($plan instanceof Plan ? $plan->features : []);
        }

        return self::$cache[$tenantId];
    }
}
