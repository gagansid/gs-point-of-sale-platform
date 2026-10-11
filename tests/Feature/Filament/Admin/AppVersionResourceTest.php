<?php

declare(strict_types=1);

use App\Enums\AppPlatform;
use App\Filament\Admin\Resources\AppVersions\AppVersionResource;
use App\Filament\Admin\Resources\AppVersions\Pages\ManageAppVersions;
use App\Models\Admin;
use App\Models\AppVersion;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Aplikasi → Versi aplikasi (SPEC panel admin). */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
});

it('tambah Android lalu ubah; API langsung memakai versi baru (cache terhapus)', function () {
    Livewire::test(ManageAppVersions::class)
        ->callAction('create', ['platform' => 'android', 'min_version' => '1.2.0', 'latest_version' => '1.4.0', 'force_update' => false])
        ->assertHasNoFormErrors();

    $version = AppVersion::query()->sole();
    expect($version->platform)->toBe(AppPlatform::Android)
        ->and(AppVersion::forPlatform(AppPlatform::Android)?->min_version)->toBe('1.2.0');

    Livewire::test(ManageAppVersions::class)
        ->callAction(TestAction::make('edit')->table($version), ['min_version' => '1.3.0', 'latest_version' => '1.5.0', 'force_update' => true])
        ->assertHasNoFormErrors();

    expect(AppVersion::forPlatform(AppPlatform::Android)?->min_version)->toBe('1.3.0')
        ->and($version->fresh()?->force_update)->toBeTrue();

    freshAuth();
    // Endpoint yang dicek versinya (system/status sengaja dikecualikan)
    $this->postJson('/api/v1/auth/login', ['email' => 'x@y.test', 'password' => 'x', 'device_uid' => 'd'], ['X-App-Version' => '1.2.9', 'X-App-Platform' => 'android'])
        ->assertStatus(426)->assertJsonPath('error.details.min_version', '1.3.0');
});

it('validasi: format versi, terbaru ≥ minimum', function (array $data, string $field) {
    Livewire::test(ManageAppVersions::class)
        ->callAction('create', ['platform' => 'ios', 'min_version' => '1.0.0', 'latest_version' => '1.0.0', ...$data])
        ->assertHasFormErrors([$field]);

    expect(AppVersion::query()->count())->toBe(0);
})->with([
    'format minimum' => [['min_version' => 'v1'], 'min_version'],
    'format terbaru' => [['latest_version' => '1.0.0-beta'], 'latest_version'],
    'bukan tiga angka (ditolak API)' => [['min_version' => '1.0'], 'min_version'],
    'terbaru < minimum' => [['min_version' => '2.0.0', 'latest_version' => '1.9.9'], 'latest_version'],
    'platform web' => [['platform' => 'web'], 'platform'],
]);

it('akun tenant tidak bisa membuka menu', function () {
    $this->actingAs(User::factory()->owner()->create(), 'web');
    auth()->guard('admin')->logout();

    $this->get(AppVersionResource::getUrl())->assertRedirect();
});
