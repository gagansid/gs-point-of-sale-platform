<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateTurnstileSettings;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Pages\Auth\Login as AdminLogin;
use App\Filament\Admin\Pages\SecuritySettings;
use App\Filament\Dashboard\Pages\Auth\Login as DashboardLogin;
use App\Models\Admin;
use App\Models\Outlet;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\Turnstile;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * Cloudflare Turnstile di halaman login (2026-10-20): on/off terpisah untuk login super admin, owner (+ lupa kata
 * sandi), dan kasir web; hanya bisa aktif bila kunci TURNSTILE_* terisi; verifikasi fail-closed.
 */
beforeEach(function () {
    config(['services.turnstile.site_key' => 'site-uji', 'services.turnstile.secret_key' => 'secret-uji']);
    // Cloudflare palsu: hanya token "token-ok" yang lolos; secret berakhiran "salah" dianggap tidak dikenal
    Http::fake(['challenges.cloudflare.com/*' => fn (HttpRequest $r) => str_ends_with((string) $r['secret'], 'salah')
        ? Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']])
        : Http::response(['success' => $r['response'] === 'token-ok'])]);
    Notification::fake();
    RateLimiter::clear('cashier-login:kopi-senja|budi|127.0.0.1');
    RateLimiter::clear('cashier-login-ip:127.0.0.1');
});

function enableTurnstile(string ...$audiences): void
{
    app(SystemSettings::class)->setTurnstile(array_fill_keys($audiences, true));
}

describe('setelan', function () {
    it('bawaan mati; aktif hanya bila kunci terisi dan disetel per halaman', function () {
        $t = app(Turnstile::class);
        expect($t->enabled(Turnstile::ADMIN))->toBeFalse();

        enableTurnstile(Turnstile::CASHIER);
        expect($t->enabled(Turnstile::CASHIER))->toBeTrue()->and($t->enabled(Turnstile::OWNER))->toBeFalse();

        config(['services.turnstile.secret_key' => null]);
        expect($t->enabled(Turnstile::CASHIER))->toBeFalse();
    });

    it('tidak bisa diaktifkan tanpa kunci; mematikan selalu boleh', function () {
        config(['services.turnstile.site_key' => null]);
        $admin = Admin::factory()->create();

        expect(fn () => app(UpdateTurnstileSettings::class)->handle($admin, [Turnstile::ADMIN => true]))->toThrow(BusinessException::class);
        app(UpdateTurnstileSettings::class)->handle($admin, [Turnstile::ADMIN => false]);
        expect(app(SystemSettings::class)->turnstileEnabled(Turnstile::ADMIN))->toBeFalse();
    });

    it('halaman Keamanan: atur per halaman login dengan konfirmasi kata sandi', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->withTwoFactor()->create(['password' => 'Rahasia-Admin-1!']), 'admin');

        Livewire::test(SecuritySettings::class)
            ->callAction('turnstile', [Turnstile::ADMIN => true, Turnstile::OWNER => false, Turnstile::CASHIER => true, 'current_password' => 'salah'])
            ->assertHasActionErrors(['current_password']);
        expect(app(SystemSettings::class)->turnstileEnabled(Turnstile::ADMIN))->toBeFalse();

        Livewire::test(SecuritySettings::class)
            ->callAction('turnstile', [Turnstile::ADMIN => true, Turnstile::OWNER => false, Turnstile::CASHIER => true, 'current_password' => 'Rahasia-Admin-1!'])
            ->assertHasNoActionErrors();

        $s = app(SystemSettings::class);
        expect($s->turnstileEnabled(Turnstile::ADMIN))->toBeTrue()->and($s->turnstileEnabled(Turnstile::OWNER))->toBeFalse()
            ->and($s->turnstileEnabled(Turnstile::CASHIER))->toBeTrue();
    });

    it('verifikasi fail-closed bila Cloudflare tidak bisa dihubungi', function () {
        Http::fake(['challenges.cloudflare.com/*' => fn () => throw new ConnectionException('timeout')]);

        expect(app(Turnstile::class)->verify('token-ok', '127.0.0.1'))->toBeFalse();
    });
});

describe('login super admin (Filament)', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->admin = Admin::factory()->create(['email' => 'admin@gspos.test', 'password' => 'Rahasia-Admin-1!']);
    });

    it('nonaktif: login tanpa widget seperti biasa', function () {
        Livewire::test(AdminLogin::class)
            ->assertDontSeeHtml('cf-turnstile')
            ->fillForm(['email' => 'admin@gspos.test', 'password' => 'Rahasia-Admin-1!'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($this->admin, 'admin');
        Http::assertNothingSent();
    });

    it('aktif: tanpa/ salah token ditolak walau kata sandi benar; token valid → masuk', function () {
        enableTurnstile(Turnstile::ADMIN);

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'admin@gspos.test', 'password' => 'Rahasia-Admin-1!'])
            ->call('authenticate')
            ->assertHasErrors(['data.turnstile'])
            ->assertDispatched('turnstile-reset');
        $this->assertGuest('admin');

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'admin@gspos.test', 'password' => 'Rahasia-Admin-1!', 'turnstile' => 'token-palsu'])
            ->call('authenticate')
            ->assertHasErrors(['data.turnstile']);
        $this->assertGuest('admin');

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'admin@gspos.test', 'password' => 'Rahasia-Admin-1!', 'turnstile' => 'token-ok'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'admin');
    });

    it('kata sandi salah dengan token valid: token di-reset untuk percobaan berikutnya', function () {
        enableTurnstile(Turnstile::ADMIN);

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'admin@gspos.test', 'password' => 'salah', 'turnstile' => 'token-ok'])
            ->call('authenticate')
            ->assertHasErrors(['data.email'])
            ->assertSet('data.turnstile', null)
            ->assertDispatched('turnstile-reset');
    });

    it('setelan owner/kasir tidak memengaruhi login admin', function () {
        enableTurnstile(Turnstile::OWNER, Turnstile::CASHIER);

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => 'admin@gspos.test', 'password' => 'Rahasia-Admin-1!'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'admin');
    });
});

describe('login owner & lupa kata sandi', function () {
    it('login dashboard (mode satu domain) mengikuti setelan owner', function () {
        Filament::setCurrentPanel('dashboard');
        $owner = User::factory()->owner()->create(['email' => 'owner@kopi.test', 'password' => 'rahasia-123']);
        enableTurnstile(Turnstile::OWNER);

        Livewire::test(DashboardLogin::class)
            ->fillForm(['email' => 'owner@kopi.test', 'password' => 'rahasia-123'])
            ->call('authenticate')
            ->assertHasErrors(['data.turnstile']);
        $this->assertGuest('web');

        Livewire::test(DashboardLogin::class)
            ->fillForm(['email' => 'owner@kopi.test', 'password' => 'rahasia-123', 'turnstile' => 'token-ok'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner, 'web');
    });

    it('lupa kata sandi: aktif → token wajib; halaman menampilkan widget', function () {
        enableTurnstile(Turnstile::OWNER);
        User::factory()->owner()->create(['email' => 'owner@kopi.test']);

        $this->get('/forgot-password')->assertOk()->assertSee('cf-turnstile', false)->assertSee('site-uji');

        $this->post('/forgot-password', ['email' => 'owner@kopi.test'])->assertSessionHasErrors(Turnstile::FIELD);
        $this->post('/forgot-password', ['email' => 'owner@kopi.test', Turnstile::FIELD => 'token-ok'])->assertSessionHasNoErrors();
    });
});

it('daftar (coba gratis) mengikuti setelan owner', function () {
    RateLimiter::clear('signup-h:127.0.0.1');
    RateLimiter::clear('signup-d:127.0.0.1');
    $body = ['business_name' => 'Kopi Fajar', 'business_type' => 'cafe', 'name' => 'Fajar', 'email' => 'fajar@kopifajar.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'];

    $this->get('/register')->assertOk()->assertDontSee('cf-turnstile', false)->assertSee('data-auth-reveal', false);

    enableTurnstile(Turnstile::OWNER);
    $this->get('/register')->assertSee('cf-turnstile', false);
    $this->post('/register', $body)->assertSessionHasErrors(Turnstile::FIELD);
    expect(Tenant::query()->count())->toBe(0);

    $this->post('/register', [...$body, Turnstile::FIELD => 'token-ok'])->assertRedirect();
    expect(Tenant::query()->count())->toBe(1);
});

describe('login kasir web', function () {
    beforeEach(function () {
        $outlet = Outlet::factory()->create();
        $tenant = Tenant::query()->findOrFail($outlet->tenant_id);
        $tenant->forceFill(['slug' => 'kopi-senja'])->save();
        TenantContext::set($tenant->id);
        User::factory()->cashier()->forOutlet($outlet)->create(['username' => 'budi', 'password' => 'rahasia-123', 'web_cashier_access' => true]);
        TenantContext::forget();
    });

    $body = fn (array $o = []): array => ['mode' => 'username', 'business' => 'kopi-senja', 'login' => 'budi', 'password' => 'rahasia-123', ...$o];

    it('nonaktif: tanpa widget & tanpa token tetap bisa masuk', function () use ($body) {
        $this->get(route('cashier.login'))->assertOk()->assertDontSee('cf-turnstile', false);
        $this->post(route('cashier.login.store'), $body())->assertRedirect(route('cashier.outlet'));
    });

    it('aktif: tanpa token ditolak, token valid masuk', function () use ($body) {
        enableTurnstile(Turnstile::CASHIER);

        $this->get(route('cashier.login'))->assertSee('cf-turnstile', false);
        $this->post(route('cashier.login.store'), $body())->assertSessionHasErrors(Turnstile::FIELD);
        $this->assertGuest('cashier');

        $this->post(route('cashier.login.store'), $body([Turnstile::FIELD => 'token-ok']))->assertRedirect(route('cashier.outlet'));
    });
});

it('halaman login menampilkan versi aplikasi & tombol lihat kata sandi', function () {
    config(['app.version' => '9.8.7']);

    $this->get(route('cashier.login'))->assertSee('v9.8.7')->assertSee('data-auth-reveal', false);
    $this->get('/forgot-password')->assertSee('v9.8.7');
});

describe('kunci dari panel', function () {
    beforeEach(function () {
        config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
        $this->admin = Admin::factory()->create();
    });

    it('kunci diisi di Keamanan: secret terenkripsi, dipakai verifikasi, menggantikan .env', function () {
        app(UpdateTurnstileSettings::class)->handle($this->admin, [
            'site_key' => '0x4AAAAAAAsitekey', 'secret_key' => '0x4AAAAAAAsecretkey', Turnstile::ADMIN => true,
        ]);

        $raw = SystemSetting::query()->find(SystemSettings::TURNSTILE_SECRET_KEY)?->value;
        expect($raw)->not->toContain('0x4AAAAAAAsecretkey')
            ->and(Turnstile::siteKey())->toBe('0x4AAAAAAAsitekey')
            ->and(Turnstile::secretKey())->toBe('0x4AAAAAAAsecretkey')
            ->and(Turnstile::keySource())->toBe('admin')
            ->and(app(Turnstile::class)->enabled(Turnstile::ADMIN))->toBeTrue();

        app(Turnstile::class)->verify('token-ok', '127.0.0.1');
        Http::assertSent(fn (HttpRequest $r): bool => $r['response'] === 'token-ok' && $r['secret'] === '0x4AAAAAAAsecretkey');
    });

    it('secret yang ditolak Cloudflare tidak disimpan', function () {
        expect(fn () => app(UpdateTurnstileSettings::class)->handle($this->admin, ['site_key' => '0x4AAAAAAAsitekey', 'secret_key' => '0x4AAAAAAAsalah']))
            ->toThrow(BusinessException::class, 'Secret key ditolak Cloudflare');
        expect(app(SystemSettings::class)->turnstileSecretKey())->toBeNull();
    });

    it('format kunci tidak valid ditolak; hapus kunci kembali ke .env', function () {
        expect(fn () => app(UpdateTurnstileSettings::class)->handle($this->admin, ['site_key' => 'ada spasi<script>']))
            ->toThrow(BusinessException::class, 'Site key tidak valid');

        app(UpdateTurnstileSettings::class)->handle($this->admin, ['site_key' => '0x4AAAAAAAsitekey', 'secret_key' => '0x4AAAAAAAsecretkey']);
        config(['services.turnstile.site_key' => 'env-site', 'services.turnstile.secret_key' => 'env-secret']);
        app(UpdateTurnstileSettings::class)->handle($this->admin, ['clear_keys' => true]);

        expect(Turnstile::keySource())->toBe('env')->and(Turnstile::siteKey())->toBe('env-site');
    });
});
