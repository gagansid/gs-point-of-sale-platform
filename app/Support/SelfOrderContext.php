<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\SelfOrderSetting;
use App\Models\Tenant;
use LogicException;

/**
 * Konteks halaman pesan dari meja (ADR 0014) untuk satu request: meja dari token QR, outlet, bisnis, setelan.
 * Diisi middleware SetSelfOrderTable sebelum controller/Livewire membaca data (tenant scope).
 */
final class SelfOrderContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Outlet $outlet,
        public readonly DiningTable $table,
        public readonly SelfOrderSetting $setting,
    ) {}

    public static function set(self $context): void
    {
        app()->instance(self::class, $context);
    }

    public static function get(): self
    {
        return app()->bound(self::class) ? app(self::class) : throw new LogicException('Konteks self-order belum diisi');
    }
}
