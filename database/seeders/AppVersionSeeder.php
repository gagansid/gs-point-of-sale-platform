<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Database\Seeder;

/**
 * Data sistem wajib: aman dijalankan berulang dan di production.
 */
final class AppVersionSeeder extends Seeder
{
    public function run(): void
    {
        AppVersion::query()->firstOrCreate(
            ['platform' => AppPlatform::Android],
            ['min_version' => '1.0.0', 'latest_version' => '1.0.0', 'force_update' => false],
        );
    }
}
