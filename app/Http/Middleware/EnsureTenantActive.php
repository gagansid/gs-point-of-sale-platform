<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\User;
use App\Support\ApiActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant suspended atau langganan habis → 403 TENANT_SUSPENDED di setiap request.
 */
final class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $owner = ApiActor::resolve($request);

        if (($owner instanceof User || $owner instanceof Device) && ! $owner->tenant->isActive()) {
            throw BusinessException::of(ErrorCode::TenantSuspended);
        }

        return $next($request);
    }
}
