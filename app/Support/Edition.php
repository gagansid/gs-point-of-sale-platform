<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Edisi produk (ADR 0009, SPEC Q33):
 *   saas         banyak tenant, halaman depan, daftar mandiri, trial/langganan (bawaan)
 *   self_hosted  jual putus: tepat satu tenant (php artisan pos:install), tanpa halaman depan,
 *                daftar mandiri, calon pelanggan, maupun trial; panel admin untuk maintenance
 */
final class Edition
{
    public const SAAS = 'saas';

    public const SELF_HOSTED = 'self_hosted';

    public static function current(): string
    {
        return config('pos.edition') === self::SELF_HOSTED ? self::SELF_HOSTED : self::SAAS;
    }

    public static function isSelfHosted(): bool
    {
        return self::current() === self::SELF_HOSTED;
    }

    public static function isSaas(): bool
    {
        return ! self::isSelfHosted();
    }
}
