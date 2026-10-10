<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\ApiActor;
use App\Support\CurrentOutlet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint khusus token USER (bukan device token).
 *
 * Pemilik token diperiksa langsung karena token user ber-ability "*" yang secara teknis
 * juga "punya" ability device. Token dari device yang sudah dicabut ikut ditolak.
 * Outlet aktif = outlet device (ADR 0010); user yang tidak ditugaskan di outlet itu ditolak.
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
        $outletId = null;

        if ($token instanceof PersonalAccessToken && $token->device_id !== null) {
            $device = $token->device;

            if ($device === null || $device->isRevoked()) {
                throw BusinessException::of(ErrorCode::DeviceNotRegistered);
            }

            if (! $device->outletIsActive()) {
                throw BusinessException::of(ErrorCode::Forbidden, 'Outlet perangkat ini sudah dinonaktifkan');
            }

            if (! $user->canAccessOutlet($device->outlet_id)) {
                throw BusinessException::of(ErrorCode::Forbidden, 'Anda tidak ditugaskan di outlet perangkat ini');
            }

            $outletId = $device->outlet_id;
        }

        CurrentOutlet::set($user, $outletId);

        return $next($request);
    }
}
