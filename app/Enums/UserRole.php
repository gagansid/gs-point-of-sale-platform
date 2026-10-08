<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/**
 * Role tenant. Satu-satunya tempat pemetaan role -> permission (docs/SPEC.md "Role & permission").
 * Kode lain tidak boleh mengecek role; selalu $user->can('order.void').
 */
enum UserRole: string implements HasColor, HasLabel
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Supervisor = 'supervisor';
    case Cashier = 'cashier';

    /**
     * Semua permission yang dikenal sistem (cermin tabel permission di SPEC).
     * Ability yang tidak ada di daftar ini tidak pernah diizinkan oleh role.
     */
    public const PERMISSIONS = [
        'order.create',
        'order.view_own',
        'order.view_all',
        'order.reprint',
        'order.discount',
        'order.discount_over_limit',
        'order.void',
        'shift.operate',
        'shift.view_all',
        'shift.force_close',
        'product.toggle_available',
        'product.manage',
        'stock.adjust',
        'report.view',
        'report.export',
        'user.manage',
        'device.manage',
        'payment_method.manage',
        'outlet.settings',
    ];

    /**
     * Pola permission per role. Wildcard: 'order.*' mencakup 'order.void'.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Manager => ['order.*', 'shift.*', 'product.*', 'stock.*', 'report.*'],
            self::Supervisor => ['order.*', 'shift.*', 'product.toggle_available'],
            self::Cashier => [
                'order.create', 'order.view_own', 'order.reprint',
                'order.discount', 'shift.operate',
            ],
        };
    }

    public function allows(string $permission): bool
    {
        // Wildcard owner ('*') tidak boleh mengizinkan ability yang tidak dikenal (ADR 0005)
        if (! self::isPermission($permission)) {
            return false;
        }

        return collect($this->permissions())
            ->contains(fn (string $pattern): bool => Str::is($pattern, $permission));
    }

    /**
     * Role yang boleh login dengan email + kata sandi di aplikasi (SPEC: owner & manager).
     * Role lain login dengan PIN di device terdaftar.
     */
    public function canUsePasswordLogin(): bool
    {
        return match ($this) {
            self::Owner, self::Manager => true,
            self::Supervisor, self::Cashier => false,
        };
    }

    /**
     * Daftar permission yang dimiliki role ini (untuk /auth/me; app menyembunyikan menu).
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        return array_values(array_filter(self::PERMISSIONS, fn (string $p): bool => $this->allows($p)));
    }

    /** Apakah ability ini permission role (bukan ability Policy seperti 'view'/'update'). */
    public static function isPermission(string $ability): bool
    {
        return in_array($ability, self::PERMISSIONS, true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Supervisor => 'Supervisor',
            self::Cashier => 'Kasir',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Owner => 'primary',
            self::Manager => 'info',
            self::Supervisor, self::Cashier => 'gray',
        };
    }
}
