<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengisi TenantContext dari user yang login. Dipasang SETELAH auth:sanctum dan
 * SEBELUM query model bisnis apa pun (docs/standards/api/auth-and-permission.md §2).
 */
final class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // Belum login: biarkan middleware auth yang menolak. Tanpa context, scope fail-closed.
            return $next($request);
        }

        $tenantId = $user->getAttribute('tenant_id');

        // Akun tanpa tenant (data rusak) tidak boleh mengakses data bisnis apa pun
        if (! is_string($tenantId) || $tenantId === '') {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        TenantContext::set($tenantId);

        return $next($request);
    }
}
