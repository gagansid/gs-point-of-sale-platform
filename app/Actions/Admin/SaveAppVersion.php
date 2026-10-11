<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AppPlatform;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\AppVersion;

/**
 * Versi aplikasi kasir per platform (SPEC panel admin → Versi Aplikasi). App di bawah min_version
 * ditolak 426 APP_UPDATE_REQUIRED (CheckAppVersion); cache per platform terhapus lewat event model.
 */
final class SaveAppVersion
{
    public const PATTERN = '/^\d+\.\d+\.\d+$/';

    /** @throws BusinessException */
    public function handle(?AppVersion $version, AppPlatform $platform, string $minVersion, string $latestVersion, bool $forceUpdate): AppVersion
    {
        if ($platform === AppPlatform::Web) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Kasir web tidak memakai versi aplikasi', ['platform' => ['Pilih Android atau iOS']]);
        }

        foreach (['min_version' => $minVersion, 'latest_version' => $latestVersion] as $field => $value) {
            if (! preg_match(self::PATTERN, $value)) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Format versi salah', [$field => ['Format versi: tiga angka bertitik, mis. 1.4.0']]);
            }
        }

        if (version_compare($latestVersion, $minVersion, '<')) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Versi terbaru tidak boleh di bawah versi minimum', [
                'latest_version' => ['Harus sama atau di atas versi minimum'],
            ]);
        }

        $version ??= AppVersion::query()->firstOrNew(['platform' => $platform]);
        $version->fill([
            'platform' => $platform,
            'min_version' => $minVersion,
            'latest_version' => $latestVersion,
            'force_update' => $forceUpdate,
        ])->save();

        return $version;
    }
}
