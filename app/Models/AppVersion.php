<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppPlatform;
use Database\Factories\AppVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Data sistem (bukan milik tenant), dikelola dari panel /admin.
 *
 * @property string $id
 * @property AppPlatform $platform
 * @property string $min_version
 * @property string $latest_version
 * @property bool $force_update
 */
final class AppVersion extends Model
{
    /** @use HasFactory<AppVersionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'platform',
        'min_version',
        'latest_version',
        'force_update',
    ];

    protected function casts(): array
    {
        return [
            'platform' => AppPlatform::class,
            'force_update' => 'boolean',
        ];
    }

    /** Versi app di bawah min_version wajib update (426 APP_UPDATE_REQUIRED). */
    public function requiresUpdate(string $appVersion): bool
    {
        return version_compare($appVersion, $this->min_version, '<');
    }
}
