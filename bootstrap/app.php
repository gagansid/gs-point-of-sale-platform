<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\BusinessException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckAppVersion;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\EnsureUserToken;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use App\Support\Domains;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // API di api.gspos.id/v1, atau /api/v1 bila subdomain tidak diatur (ADR 0008)
        then: function (): void {
            Route::middleware('api')
                ->domain(Domains::api())
                ->prefix(Domains::apiPrefix())
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Paling awal agar semua log (termasuk error maintenance) punya request_id
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // ForceJsonResponse harus sebelum auth agar request tanpa token mendapat 401 JSON
        $middleware->api(prepend: [ForceJsonResponse::class]);

        // Limiter "api" didefinisikan di AppServiceProvider (120/menit per token, SPEC Keamanan)
        $middleware->throttleApi();

        // Dipakai di routes/api.php: ['auth:sanctum', 'tenant', 'tenant.active', 'token.user|token.device']
        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'tenant.active' => EnsureTenantActive::class,
            'token.user' => EnsureUserToken::class,
            'token.device' => EnsureDeviceToken::class,
            'app.version' => CheckAppVersion::class,
        ]);

        // Wajib sebelum route model binding: binding menjalankan query yang dibatasi TenantScope
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error bisnis adalah alur normal, bukan bug: jangan memenuhi log/Sentry
        $exceptions->dontReport(BusinessException::class);

        // Nilai rahasia tidak boleh ikut tersimpan di session saat validasi gagal
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'pin',
            'approver_pin',
        ]);

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
