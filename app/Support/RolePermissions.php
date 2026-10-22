<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\RolePermission;

/**
 * Permission yang berlaku per role di satu bisnis (ADR 0026): pengaturan owner (tabel role_permissions) atau bawaan
 * UserRole::permissions(). Owner selalu semua permission; permission khusus owner (UserRole::OWNER_ONLY) tidak pernah
 * berlaku untuk role lain walau tersimpan. Dibaca sekali per tenant per request (cache statis, seperti Features).
 */
final class RolePermissions
{
    /** @var array<string, array<string, list<string>>> tenant id → role → permission yang diatur owner */
    private static array $cache = [];

    /** @return list<string> permission yang berlaku, urut seperti UserRole::PERMISSIONS */
    public static function granted(UserRole $role, ?string $tenantId): array
    {
        if (! $role->isConfigurable()) {
            return $role->grantedPermissions();
        }

        $custom = $tenantId !== null ? (self::forTenant($tenantId)[$role->value] ?? null) : null;

        if ($custom === null) {
            return $role->grantedPermissions();
        }

        return array_values(array_filter(UserRole::PERMISSIONS, fn (string $p): bool => UserRole::isDelegable($p) && in_array($p, $custom, true)));
    }

    public static function allows(UserRole $role, ?string $tenantId, string $permission): bool
    {
        if (! UserRole::isPermission($permission)) {
            return false;
        }

        return ! $role->isConfigurable()
            ? $role->allows($permission)
            : in_array($permission, self::granted($role, $tenantId), true);
    }

    /** Bisnis ini sudah mengubah hak akses role tersebut (bukan bawaan). */
    public static function isCustomized(UserRole $role, string $tenantId): bool
    {
        return array_key_exists($role->value, self::forTenant($tenantId));
    }

    /** Lupakan cache (setelah hak akses diubah, dan antar test). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array<string, list<string>> */
    private static function forTenant(string $tenantId): array
    {
        return self::$cache[$tenantId] ??= RolePermission::query()->forTenant($tenantId)->get(['role', 'permissions'])
            ->mapWithKeys(fn (RolePermission $row): array => [$row->role->value => array_map(strval(...), $row->permissions)])
            ->all();
    }
}
