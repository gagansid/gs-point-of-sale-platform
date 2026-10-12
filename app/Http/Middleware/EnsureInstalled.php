<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use App\Support\Installer\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Middleware global paling awal (ADR 0016):
 *   - sudah terinstal: /install 404
 *   - belum terinstal: siapkan .env/APP_KEY & session file, web dialihkan ke /install, API 503 MAINTENANCE
 */
final class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $isInstallRoute = $request->is('install', 'install/*');

        if (Installation::isInstalled()) {
            abort_if($isInstallRoute, 404);

            return $next($request);
        }

        try {
            Installation::prepareRuntime($request);
        } catch (Throwable $e) {
            return response(view('install.unwritable', ['message' => $e->getMessage()]), 500);
        }

        if ($isInstallRoute || $request->is('up')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error(ErrorCode::Maintenance, 'Aplikasi belum diinstal', 503);
        }

        return redirect('/install');
    }
}
