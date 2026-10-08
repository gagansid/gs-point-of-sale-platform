<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AppPlatform;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak aplikasi Flutter versi lama: X-App-Version di bawah min_version → 426.
 * Header X-App-Platform: android (default) / ios.
 */
final class CheckAppVersion
{
    public const VERSION_HEADER = 'X-App-Version';

    public const PLATFORM_HEADER = 'X-App-Platform';

    public function handle(Request $request, Closure $next): Response
    {
        $version = (string) $request->header(self::VERSION_HEADER, '');

        // Wajib versi semantik (1.2.3): header kosong/rusak diperlakukan sebagai app usang
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw BusinessException::of(ErrorCode::AppUpdateRequired);
        }

        $platform = AppPlatform::tryFrom(strtolower((string) $request->header(self::PLATFORM_HEADER, 'android')))
            ?? AppPlatform::Android;

        $required = AppVersion::forPlatform($platform);

        if ($required?->requiresUpdate($version)) {
            throw BusinessException::of(ErrorCode::AppUpdateRequired, details: [
                'min_version' => $required->min_version,
                'latest_version' => $required->latest_version,
            ]);
        }

        return $next($request);
    }
}
