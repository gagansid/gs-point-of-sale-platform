<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Akses bertipe ke setelan sistem global. Nilai di-cache (driver database) dan cache
 * dihapus setiap kali nilai diubah, sehingga perubahan langsung berlaku.
 */
final class SystemSettings
{
    public const ADMIN_TWO_FACTOR_REQUIRED = 'security.admin_two_factor_required';

    private const CACHE_PREFIX = 'system_settings:';

    /**
     * Wajibkan 2FA untuk semua super admin. Default dari config (aman: aktif) bila belum pernah diatur.
     */
    public function adminTwoFactorRequired(): bool
    {
        return (bool) $this->get(self::ADMIN_TWO_FACTOR_REQUIRED, config('pos.security.admin_two_factor_required'));
    }

    public function setAdminTwoFactorRequired(bool $required, ?Admin $by = null): void
    {
        $this->set(self::ADMIN_TWO_FACTOR_REQUIRED, $required, $by);
    }

    private function get(string $key, mixed $default): mixed
    {
        $stored = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key): array {
            $setting = SystemSetting::query()->find($key);

            // Dibungkus array agar "belum diatur" bisa dibedakan dari nilai false/null
            return $setting === null ? [] : ['value' => $setting->value];
        });

        return array_key_exists('value', $stored) ? $stored['value'] : $default;
    }

    private function set(string $key, mixed $value, ?Admin $by): void
    {
        SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);

        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
