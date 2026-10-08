<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Support\SystemSettings;

beforeEach(function () {
    $this->setUpUrl = '/admin/multi-factor-authentication/set-up';
});

it('mengarahkan tamu ke halaman login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('akun tenant tidak bisa masuk panel admin', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner, 'web')->get('/admin')->assertRedirect('/admin/login');
});

it('admin dengan 2FA bisa membuka panel', function () {
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin')
        ->get('/admin')
        ->assertOk();
});

describe('wajib 2FA bisa on/off dari setelan', function () {
    it('ON (default): admin tanpa 2FA wajib mengaturnya dulu', function () {
        expect(app(SystemSettings::class)->adminTwoFactorRequired())->toBeTrue();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get('/admin/tenants')
            ->assertRedirect($this->setUpUrl);
    });

    it('OFF: admin tanpa 2FA boleh memakai panel', function () {
        app(SystemSettings::class)->setAdminTwoFactorRequired(false);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get('/admin/tenants')
            ->assertOk();
    });

    it('perubahan langsung berlaku tanpa membangun ulang route', function () {
        $admin = Admin::factory()->create();
        $settings = app(SystemSettings::class);

        $settings->setAdminTwoFactorRequired(false);
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk();

        $settings->setAdminTwoFactorRequired(true);
        $this->actingAs($admin, 'admin')->get('/admin')->assertRedirect($this->setUpUrl);
    });
});
