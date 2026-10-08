<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Support\ApiActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint khusus DEVICE token (layar PIN sebelum kasir login, ADR 0002).
 */
final class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = ApiActor::resolve($request);

        if (! $device instanceof Device || ! $device->tokenCan('device') || $device->isRevoked()) {
            throw BusinessException::of(ErrorCode::DeviceNotRegistered);
        }

        // Jejak pemakaian device, ditulis paling sering 1x per menit agar tidak membebani DB
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill([
                'last_seen_at' => now(),
                'app_version' => $request->header(CheckAppVersion::VERSION_HEADER, $device->app_version),
            ])->saveQuietly();
        }

        return $next($request);
    }
}
