<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Memetakan semua exception pada /api/* ke envelope gagal standar.
 * Didaftarkan di bootstrap/app.php; halaman web/Filament memakai penanganan bawaan.
 *
 * Hanya memakai kode resmi ErrorCode. Detail teknis (stack trace, SQL, nama kelas) tidak
 * pernah dikirim ke client, termasuk saat APP_DEBUG=true.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            // Respons yang sengaja dibuat kode (abort dengan response) dikembalikan apa adanya
            $e instanceof HttpResponseException => null,

            $e instanceof BusinessException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details),

            $e instanceof ValidationException => ApiResponse::error(ErrorCode::ValidationError, details: $e->errors()),

            $e instanceof AuthenticationException => ApiResponse::error(ErrorCode::Unauthenticated),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(ErrorCode::Forbidden),

            // Method salah dijawab 404: endpoint "tidak ada" untuk method tersebut, sekaligus
            // menyulitkan pemetaan endpoint oleh penyerang
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException,
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(ErrorCode::NotFound),

            $e instanceof PostTooLargeException => ApiResponse::error(
                ErrorCode::ValidationError,
                'Ukuran data terlalu besar',
                details: ['body' => ['Ukuran data melebihi batas yang diizinkan']],
            ),

            // Retry-After & X-RateLimit-* diteruskan agar client tahu kapan boleh mencoba lagi
            $e instanceof ThrottleRequestsException => ApiResponse::error(ErrorCode::TooManyRequests, headers: $e->getHeaders()),

            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => ApiResponse::error(ErrorCode::Maintenance, headers: $e->getHeaders()),

            // Error client lain (400, 419, dst.) diperlakukan sebagai permintaan tidak valid
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => ApiResponse::error(
                ErrorCode::ValidationError,
                'Permintaan tidak valid',
            ),

            default => ApiResponse::error(ErrorCode::ServerError),
        };
    }
}
