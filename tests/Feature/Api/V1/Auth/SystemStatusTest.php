<?php

declare(strict_types=1);

use App\Enums\AppPlatform;
use App\Models\Announcement;
use App\Models\AppVersion;

it('publik: mengembalikan versi app dan pengumuman aktif tanpa token & header versi', function () {
    AppVersion::factory()->create(['platform' => AppPlatform::Android, 'min_version' => '1.2.0', 'latest_version' => '1.3.0']);
    $live = Announcement::factory()->create(['title' => 'Maintenance Minggu']);
    Announcement::factory()->expired()->create();

    $this->getJson('/api/v1/system/status')
        ->assertOk()
        ->assertJsonPath('data.maintenance', false)
        ->assertJsonPath('data.app_versions.0.min_version', '1.2.0')
        ->assertJsonPath('data.app_versions.0.latest_version', '1.3.0')
        ->assertJsonCount(1, 'data.announcements')
        ->assertJsonPath('data.announcements.0.id', $live->id)
        ->assertJsonPath('data.server_time', fn (string $time) => str_ends_with($time, 'Z'));
});

it('503 MAINTENANCE saat sistem dalam mode maintenance', function () {
    $this->app->maintenanceMode()->activate([]);

    assertApiError($this->getJson('/api/v1/system/status'), 'MAINTENANCE', 503);

    $this->app->maintenanceMode()->deactivate();
});
