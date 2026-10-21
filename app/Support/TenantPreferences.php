<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Preferensi bisnis tingkat owner (Q128) di `tenants.preferences` (JSON): nilai dibaca dengan bawaan aman dan
 * divalidasi di sini, ditulis lewat App\Actions\Tenant\UpdateTenantPreferences.
 */
final class TenantPreferences
{
    /** Panduan "Mulai berjualan" disembunyikan owner. */
    public const SETUP_DISMISSED = 'setup_dismissed';

    public static function get(Tenant $tenant, string $key, mixed $default = null): mixed
    {
        return ($tenant->preferences ?? [])[$key] ?? $default;
    }

    public static function setupDismissed(Tenant $tenant): bool
    {
        return (bool) self::get($tenant, self::SETUP_DISMISSED, false);
    }
}
