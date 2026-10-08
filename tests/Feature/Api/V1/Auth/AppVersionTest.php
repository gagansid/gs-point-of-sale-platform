<?php

declare(strict_types=1);

use App\Enums\AppPlatform;
use App\Models\AppVersion;

beforeEach(function () {
    AppVersion::factory()->create(['platform' => AppPlatform::Android, 'min_version' => '1.2.0', 'latest_version' => '1.4.0']);
    AppVersion::factory()->create(['platform' => AppPlatform::Ios, 'min_version' => '2.0.0', 'latest_version' => '2.0.0']);
});

it('menolak request tanpa X-App-Version atau dengan format rusak', function (array $headers) {
    assertApiError($this->postJson('/api/v1/auth/login', [], $headers), 'APP_UPDATE_REQUIRED', 426);
})->with([
    'tanpa header' => [[]],
    'format rusak' => [['X-App-Version' => '1.2']],
    'injeksi' => [['X-App-Version' => "1.2.0'; DROP TABLE users"]],
]);

it('426 APP_UPDATE_REQUIRED untuk versi di bawah minimum, beserta versi terbaru', function () {
    $this->postJson('/api/v1/auth/login', [], ['X-App-Version' => '1.1.9'])
        ->assertStatus(426)
        ->assertJsonPath('error.details.min_version', '1.2.0')
        ->assertJsonPath('error.details.latest_version', '1.4.0');
});

it('meneruskan versi yang memenuhi minimum (lanjut ke validasi)', function () {
    assertApiError($this->postJson('/api/v1/auth/login', [], ['X-App-Version' => '1.2.0']), 'VALIDATION_ERROR', 422);
});

it('memakai versi minimum sesuai platform', function () {
    $headers = ['X-App-Version' => '1.5.0', 'X-App-Platform' => 'ios'];

    assertApiError($this->postJson('/api/v1/auth/login', [], $headers), 'APP_UPDATE_REQUIRED', 426);
});

it('perubahan versi minimum dari admin langsung berlaku (cache dihapus)', function () {
    $this->postJson('/api/v1/auth/login', [], ['X-App-Version' => '1.2.0'])->assertStatus(422);

    AppVersion::query()->where('platform', AppPlatform::Android)->update(['min_version' => '1.3.0']);
    AppVersion::query()->where('platform', AppPlatform::Android)->first()?->touch();

    $this->postJson('/api/v1/auth/login', [], ['X-App-Version' => '1.2.0'])->assertStatus(426);
});
