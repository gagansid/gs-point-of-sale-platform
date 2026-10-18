<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang fitur paket di API (ADR 0025): `feature:member` → 403 FEATURE_NOT_AVAILABLE (`details.feature`) bila paket
 * tenant tidak memuat fitur itu. Dipasang setelah middleware `tenant` (butuh tenant context).
 */
final class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        Features::ensure($feature);

        return $next($request);
    }
}
