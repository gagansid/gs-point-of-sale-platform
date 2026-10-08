<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\ApiActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint khusus token USER (bukan device token).
 *
 * Pemilik token diperiksa langsung karena token user ber-ability "*" yang secara teknis
 * juga "punya" ability device. Token dari device yang sudah dicabut ikut ditolak.
 */
final class EnsureUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = ApiActor::resolve($request);

        if (! $user instanceof User || ! $user->is_active) {
            throw BusinessException::of(ErrorCode::Unauthenticated);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->device_id !== null) {
            $device = $token->device;

            if ($device === null || $device->isRevoked()) {
                throw BusinessException::of(ErrorCode::DeviceNotRegistered);
            }
        }

        return $next($request);
    }
}
