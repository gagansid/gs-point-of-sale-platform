<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\BusinessException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Paling awal agar semua log (termasuk error maintenance) punya request_id
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // ForceJsonResponse harus sebelum auth agar request tanpa token mendapat 401 JSON
        $middleware->api(prepend: [ForceJsonResponse::class]);

        // Limiter "api" didefinisikan di AppServiceProvider (120/menit per token, SPEC Keamanan)
        $middleware->throttleApi();
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
