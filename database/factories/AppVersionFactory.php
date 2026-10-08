<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppPlatform;
use App\Models\AppVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersion>
 */
final class AppVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform' => AppPlatform::Android,
            'min_version' => '1.0.0',
            'latest_version' => '1.0.0',
            'force_update' => false,
        ];
    }
}
