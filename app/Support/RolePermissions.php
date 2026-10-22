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

    /**
     * Hak akses role + pengecualian karyawan (ADR 0026, opsi B): tambahan dilengkapi izin tersiratnya, lalu yang dicabut
     * dibuang beserta izin yang bergantung padanya. Izin khusus owner tidak pernah bisa ditambahkan.
     *
     * @param  list<string>  $granted  hak akses role
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string>
     */
    public static function applyOverrides(array $granted, array $add, array $remove): array
    {
        if ($add === [] && $remove === []) {
            return $granted;
        }

        $set = array_flip([...$granted, ...UserRole::normalize($add)]);

        foreach ($remove as $permission) {
            unset($set[$permission]);
        }

        // Izin yang butuh izin lain (IMPLIES) gugur bila dasarnya dicabut, mis. member.manage tanpa member.view;
        // diulang sampai stabil untuk rantai report.view → report.view_shift → report.view_own
        do {
            $dropped = false;

            foreach (UserRole::IMPLIES as $permission => $implied) {
                if (isset($set[$permission]) && array_diff($implied, array_keys($set)) !== []) {
                    unset($set[$permission]);
                    $dropped = true;
                }
            }
        } while ($dropped);

        return array_values(array_filter(UserRole::PERMISSIONS, fn (string $p): bool => isset($set[$p])));
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
