<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * Fitur yang boleh dipakai tenant aktif menurut paket langganan (ADR 0024). Jual putus, tanpa konteks tenant, atau
 * tenant tanpa paket = semua fitur. Dipakai menu dashboard & mesin (promo, kasbon, self-order, dll.).
 */
final class Features
{
    /** @var array<string, list<string>|null> cache per tenant per request */
    private static array $cache = [];

    public static function enabled(string $feature, ?Tenant $tenant = null): bool
    {
        if (Edition::isSelfHosted()) {
            return true;
        }

        $tenantId = $tenant->id ?? TenantContext::id();
        if ($tenantId === null) {
            return true;
        }

        if (! array_key_exists($tenantId, self::$cache)) {
            $planId = $tenant !== null ? $tenant->plan_id : Tenant::query()->whereKey($tenantId)->value('plan_id');
            $plan = $planId !== null ? Plan::query()->find($planId) : null;
            self::$cache[$tenantId] = $planId === null ? null : ($plan instanceof Plan ? $plan->features : []);
        }

        $features = self::$cache[$tenantId];

        return $features === null || in_array($feature, $features, true);
    }

    /** Lupakan cache (setelah paket tenant berubah, dan antar test). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
