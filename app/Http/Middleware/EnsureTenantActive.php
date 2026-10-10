<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\TenantAccess;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\User;
use App\Support\ApiActor;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akses tenant di setiap request API (ADR 0009, SPEC Q32):
 *   ditangguhkan                  → 403 TENANT_SUSPENDED
 *   trial/langganan habis         → GET boleh; request yang mengubah data → 403 SUBSCRIPTION_EXPIRED
 */
final class EnsureTenantActive
{
    /** Tetap diizinkan saat hanya-baca: kasir bisa login untuk melihat pesan & keluar. */
    private const READ_ONLY_ALLOWED_ROUTES = [
        'api.v1.auth.pin-login',
        'api.v1.auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $owner = ApiActor::resolve($request);

        if (! $owner instanceof User && ! $owner instanceof Device) {
            return $next($request);
        }

        $access = $owner->tenant->access();

        if ($access === TenantAccess::Blocked) {
            throw BusinessException::of(ErrorCode::TenantSuspended);
        }

        if ($access === TenantAccess::ReadOnly) {
            // Pengaman lapis kedua di BelongsToTenant membaca flag ini
            TenantContext::set($owner->tenant_id, readOnly: true);

            if (! $request->isMethodSafe() && ! $request->routeIs(...self::READ_ONLY_ALLOWED_ROUTES)) {
                throw BusinessException::of(ErrorCode::SubscriptionExpired);
            }
        }

        return $next($request);
    }
}
