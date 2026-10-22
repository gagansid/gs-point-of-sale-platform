<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/**
 * Role tenant. Satu-satunya tempat pemetaan BAWAAN role -> permission (docs/SPEC.md "Role & permission").
 * Owner bisa mengubah permission manager/supervisor/kasir per bisnis (ADR 0026); yang berlaku dibaca lewat
 * App\Support\RolePermissions. Kode lain tidak boleh mengecek role; selalu $user->can('order.void').
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
        // Retur sebagian (ADR 0018)
        'order.refund',
        'shift.operate',
        'shift.view_all',
        'shift.force_close',
        // Shift bersama per perangkat (ADR 0017): koreksi kas awal carry-over, kas keluar di atas batas tanpa PIN
        'shift.edit_opening_cash',
        'shift.cash_out_over_limit',
        'product.toggle_available',
        'product.manage',
        'stock.adjust',
        // Stok masuk & supplier (ADR 0015)
        'stock.receive',
        'stock.opname',
        'stock.transfer',
        'supplier.manage',
        // Purchase order ke supplier (ADR 0021)
        'purchase.manage',
        'report.view',
        'report.view_shift',
        'report.view_own',
        'report.export',
        'user.manage',
        'device.manage',
        'payment_method.manage',
        'outlet.settings',
        'outlet.manage',
        'outlet.access_all',
        // Riwayat perubahan / audit log (Q58)
        'audit.view',
        // Program member (ADR 0013)
        'member.view',
        'member.create',
        'member.manage',
        'member.points_adjust',
        'member.settings',
        // Self-order QR meja (ADR 0014)
        'table.manage',
        'self_order.handle',
        'payment_gateway.manage',
        // Promo otomatis (ADR 0022)
        'promo.manage',
        // Paket langganan & tagihan SaaS (ADR 0024)
        'billing.manage',
        // Profil bisnis & tampilan dashboard (Q128)
        'business.settings',
        // Panel /dashboard penuh: Beranda & menu sesuai permission lain (ADR 0026). Tanpa ini hanya menu Laporan
        // (bila punya report.view_shift) — pengganti cek role hasFullDashboard
        'dashboard.access',
        // Mengatur hak akses role (ADR 0026)
        'access.manage',
    ];

    /**
     * Permission yang hanya untuk owner dan tidak bisa diberikan ke role lain (ADR 0026): langganan & tagihan,
     * pengaturan hak akses itu sendiri (cegah naik hak), dan otomatis memegang semua outlet (struktur akses outlet).
     */
    public const OWNER_ONLY = [
        'billing.manage',
        'access.manage',
        'outlet.access_all',
    ];

    /**
     * Permission yang otomatis ikut diberikan (ADR 0026): mengelola/menindak butuh bisa melihat.
     *
     * @var array<string, list<string>>
     */
    public const IMPLIES = [
        'order.view_all' => ['order.view_own'],
        'shift.force_close' => ['shift.view_all'],
        'report.view' => ['report.view_shift', 'report.view_own'],
        'report.view_shift' => ['report.view_own'],
        'member.create' => ['member.view'],
        'member.manage' => ['member.view'],
        'member.points_adjust' => ['member.view'],
        'member.settings' => ['member.view'],
    ];

    /**
     * Permission yang tetap berlaku saat tenant hanya-baca (ADR 0009): hanya melihat/mencetak ulang.
     */
    public const READ_PERMISSIONS = [
        'order.view_own',
        'order.view_all',
        'order.reprint',
        'shift.view_all',
        'report.view',
        'report.view_shift',
        'report.view_own',
        'report.export',
        'outlet.access_all',
        'audit.view',
        'member.view',
    ];

    public static function isReadPermission(string $permission): bool
    {
        return in_array($permission, self::READ_PERMISSIONS, true);
    }

    /**
     * Pola permission per role. Wildcard: 'order.*' mencakup 'order.void'.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Manager => ['dashboard.access', 'order.*', 'shift.*', 'product.*', 'stock.*', 'report.*', 'audit.view', 'member.view', 'member.create', 'member.manage', 'member.points_adjust', 'table.manage', 'self_order.handle', 'supplier.manage', 'purchase.manage', 'promo.manage'],
            // Tanpa order.discount_over_limit: diskon supervisor dibatasi discount_limits.supervisor (Q18, GSPOS-SEC-014)
            self::Supervisor => [
                'order.create', 'order.view_own', 'order.view_all', 'order.reprint',
                'order.discount', 'order.void', 'order.refund', 'shift.*', 'product.toggle_available',
                // Laporan operasional maks. 31 hari + rekap shift sendiri + export (Q49, Q52)
                'report.view_shift', 'report.view_own', 'report.export',
                'member.view', 'member.create', 'self_order.handle',
                // Catat barang datang di outlet (ADR 0015)
                'stock.receive',
            ],
            self::Cashier => [
                'order.create', 'order.view_own', 'order.reprint',
                'order.discount', 'shift.operate',
                // Tandai menu habis/tersedia di outlet sendiri (Q115)
                'product.toggle_available',
                // Rekap shift sendiri (X/Z-report), Q49
                'report.view_own',
                'member.view', 'member.create', 'self_order.handle',
            ],
        };
    }

    /** Bisa diberikan owner ke role lain lewat Hak akses (ADR 0026). */
    public static function isDelegable(string $permission): bool
    {
        return self::isPermission($permission) && ! in_array($permission, self::OWNER_ONLY, true);
    }

    /**
     * Lengkapi daftar dengan permission yang tersirat (IMPLIES), buang yang tidak dikenal/khusus owner, urut katalog.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function normalize(array $permissions): array
    {
        $set = array_flip($permissions);

        foreach (self::IMPLIES as $permission => $implied) {
            if (isset($set[$permission])) {
                foreach ($implied as $p) {
                    $set[$p] = true;
                }
            }
        }

        return array_values(array_filter(self::PERMISSIONS, fn (string $p): bool => isset($set[$p]) && self::isDelegable($p)));
    }

    /** Role yang permission-nya bisa diatur owner (ADR 0026). */
    public function isConfigurable(): bool
    {
        return $this !== self::Owner;
    }

    /**
     * Tingkat role: pengelola karyawan non-owner hanya boleh mengatur role di bawahnya (ADR 0026).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Supervisor => 1,
            self::Cashier => 0,
        };
    }

    /**
     * Role yang memiliki permission (untuk filter query, mis. user yang memegang semua outlet).
     *
     * @return list<self>
     */
    public static function withPermission(string $permission): array
    {
        return array_values(array_filter(self::cases(), fn (self $role): bool => $role->allows($permission)));
    }

    /** Bawaan role (tanpa pengaturan owner). Untuk keputusan akses selalu $user->can() / hasPermission(). */
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

    /** Akses kasir web bawaan saat karyawan ditambah (Q54): aktif untuk role yang login dengan username. */
    public function defaultWebCashierAccess(): bool
    {
        return ! $this->canUsePasswordLogin();
    }

    /**
     * Daftar permission BAWAAN role ini. Yang berlaku di satu bisnis: User::grantedPermissions() (ADR 0026).
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
        // Satu warna per level (palet role-* di Theme): owner → manager → supervisor → kasir
        return match ($this) {
            self::Owner => 'role-owner',
            self::Manager => 'role-manager',
            self::Supervisor => 'role-supervisor',
            self::Cashier => 'role-cashier',
        };
    }
}
