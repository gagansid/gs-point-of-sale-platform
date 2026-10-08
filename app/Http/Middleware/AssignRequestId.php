<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memberi setiap request ID unik untuk pelacakan log/Sentry dan meta.request_id.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * ID dari client hanya diterima jika formatnya aman: mencegah log injection
     * (baris baru, karakter kontrol) dan header berukuran besar.
     */
    private const SAFE_PATTERN = '/^[A-Za-z0-9\-_]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $requestId = is_string($incoming) && preg_match(self::SAFE_PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::uuid7();

        // Context otomatis ikut di setiap baris log dan job queue yang dikirim dari request ini
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
