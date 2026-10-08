<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons (docs/standards/security.md §3).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        // Panel tidak pernah ditampilkan dalam iframe: cegah clickjacking & halaman phishing pembungkus
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Jangan beri tahu penyerang versi PHP yang dipakai
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is('api/*')) {
            // API hanya mengembalikan JSON: larang semua pemuatan sumber daya
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

            // Data transaksi tidak boleh tersimpan di cache perangkat/proxy, kecuali respons
            // yang sengaja memakai ETag (mis. /catalog)
            if (! $headers->has('ETag')) {
                $headers->set('Cache-Control', 'no-store, private');
            }
        }

        return $response;
    }
}
