<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Proxy yang dipercaya (POS_TRUSTED_PROXIES), GSPOS-SEC-015.
 *
 * Di belakang proxy (Cloudflare, load balancer) Laravel harus tahu IP proxy agar membaca IP asli
 * pengguna dari X-Forwarded-For dan skema https dari X-Forwarded-Proto. Tanpa itu semua rate limit per
 * IP (login, PIN, signup) dipakai bersama seluruh pengguna dan HSTS tidak pernah terkirim.
 *
 * Nilai: kosong (default, tanpa proxy), "cloudflare", daftar IP/CIDR, atau kombinasi dipisah koma.
 * "*" sengaja tidak didukung: mempercayai siapa pun membuat X-Forwarded-For bisa dipalsukan.
 */
final class TrustedProxies
{
    /** Rentang IP Cloudflare dari https://www.cloudflare.com/ips/ (dicek 2026-10-11). */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @return list<string> */
    public static function fromConfig(?string $value = null): array
    {
        $value ??= (string) config('pos.trusted_proxies');
        $proxies = [];

        foreach (array_filter(array_map(trim(...), explode(',', $value))) as $entry) {
            if (strtolower($entry) === 'cloudflare') {
                array_push($proxies, ...self::CLOUDFLARE);
            } elseif (self::isIpOrCidr($entry)) {
                $proxies[] = $entry;
            } else {
                // Salah ketik tidak boleh membuat aplikasi mati; cukup diabaikan dan dicatat
                Log::warning('POS_TRUSTED_PROXIES: entri tidak valid diabaikan', ['entry' => $entry]);
            }
        }

        return array_values(array_unique($proxies));
    }

    private static function isIpOrCidr(string $entry): bool
    {
        [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask >= 0 && (int) $mask <= $max;
    }
}
