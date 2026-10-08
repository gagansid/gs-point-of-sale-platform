<?php

declare(strict_types=1);

use App\Support\SystemSettings;

it('memakai default dari config bila belum pernah diatur', function () {
    config(['pos.security.admin_two_factor_required' => false]);

    expect(app(SystemSettings::class)->adminTwoFactorRequired())->toBeFalse();
});

it('nilai tersimpan menang atas default dan cache ikut diperbarui', function () {
    $settings = app(SystemSettings::class);

    expect($settings->adminTwoFactorRequired())->toBeTrue();

    $settings->setAdminTwoFactorRequired(false);
    expect($settings->adminTwoFactorRequired())->toBeFalse();

    $settings->setAdminTwoFactorRequired(true);
    expect($settings->adminTwoFactorRequired())->toBeTrue();
});
