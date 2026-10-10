<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Subdomain per bagian aplikasi (ADR 0008):
 *
 *   gspos.id         halaman depan (penjualan) + form hubungi sales
 *   app.gspos.id     panel pelanggan (owner/manager)  — dulu /dashboard
 *   admin.gspos.id   panel super admin                — dulu /admin
 *   api.gspos.id/v1  REST API aplikasi Flutter        — dulu /api/v1
 *
 * Bila POS_APP_DOMAIN kosong (test, atau hosting tanpa subdomain) semuanya kembali ke satu
 * domain dengan path lama, sehingga route & test lama tetap berlaku.
 */
final class Domains
{
    public static function enabled(): bool
    {
        return filled(config('pos.domains.app'));
    }

    /** Domain halaman depan; diambil dari APP_URL bila tidak diatur. */
    public static function main(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $configured = config('pos.domains.main');

        return is_string($configured) && $configured !== ''
            ? $configured
            : parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public static function app(): ?string
    {
        return self::enabled() ? (string) config('pos.domains.app') : null;
    }

    public static function admin(): ?string
    {
        return self::enabled() ? (string) config('pos.domains.admin') : null;
    }

    public static function api(): ?string
    {
        return self::enabled() ? (string) config('pos.domains.api') : null;
    }

    /**
     * URL absolut ke subdomain lain dengan skema & port request saat ini (port lokal :8000 ikut).
     */
    public static function url(string $domain, string $path = '', ?string $query = null): string
    {
        $request = request();
        $port = $request->getPort();
        $standardPort = $request->isSecure() ? 443 : 80;

        return $request->getScheme().'://'.$domain
            .($port !== null && (int) $port !== $standardPort ? ':'.$port : '')
            .'/'.ltrim($path, '/')
            .(filled($query) ? '?'.$query : '');
    }

    /** Path panel pelanggan: kosong di subdomain sendiri, "dashboard" bila satu domain. */
    public static function appPath(): string
    {
        return self::enabled() ? '' : 'dashboard';
    }

    public static function adminPath(): string
    {
        return self::enabled() ? '' : 'admin';
    }

    /** Prefix API: "v1" di api.gspos.id, "api/v1" bila satu domain. */
    public static function apiPrefix(): string
    {
        return self::enabled() ? 'v1' : 'api/v1';
    }
}
