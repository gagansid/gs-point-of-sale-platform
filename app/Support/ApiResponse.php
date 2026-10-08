<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;

/**
 * Satu-satunya pembuat respons API (docs/standards/api/response-envelope.md).
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => self::requestId(), ...$meta],
        ], $status, $headers);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginated(LengthAwarePaginator $page, string $resource, string $message = 'OK'): JsonResponse
    {
        return self::success($resource::collection($page->items())->resolve(), $message, meta: [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function error(
        string|ErrorCode $code,
        ?string $message = null,
        ?int $status = null,
        mixed $details = null,
        array $headers = [],
    ): JsonResponse {
        if ($code instanceof ErrorCode) {
            $message ??= $code->message();
            $status ??= $code->status();
            $code = $code->value;
        }

        return response()->json([
            'success' => false,
            'message' => $message ?? 'Terjadi kesalahan',
            'error' => ['code' => $code, 'details' => $details],
            'meta' => ['request_id' => self::requestId()],
        ], $status ?? 500, $headers);
    }

    private static function requestId(): ?string
    {
        $id = Context::get('request_id');

        return is_string($id) ? $id : null;
    }
}
