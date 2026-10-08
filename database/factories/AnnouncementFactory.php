<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
final class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Maintenance terjadwal',
            'body' => fake()->sentence(),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ];
    }

    public function expired(): self
    {
        return $this->state(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
    }

    public function scheduled(): self
    {
        return $this->state(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    }
}
