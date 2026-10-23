<?php

declare(strict_types=1);

use App\Actions\Admin\RotateAppKey;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Models\Admin;
use App\Models\PaymentGatewaySetting;
use App\Support\Installer\EnvironmentFile;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * GSPOS-SEC-025 (docs/security/reports/2026-10-23-audit.md): APP_KEY produksi bisa diganti dari key lokal yang ikut
 * paket hosting tanpa kehilangan data terenkripsi; key lama dicabut dan semua session web diputus.
 * .env memakai folder sementara, sehingga .env lokal tidak tersentuh.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/gspos-rotate-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);
    app()->useEnvironmentPath($this->dir);
    $this->oldKey = (string) config('app.key');
    file_put_contents($this->dir.'/.env', "APP_NAME=gs.POS\nAPP_KEY={$this->oldKey}\nAPP_PREVIOUS_KEYS=\n");
    $this->env = new EnvironmentFile($this->dir.'/.env');

    // Data rahasia yang dienkripsi dengan key lama
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdatePaymentGateway::class)->handle(['enabled' => false, 'is_production' => false, 'server_key' => 'SB-Mid-server-abc', 'client_key' => 'SB-Mid-client-xyz']);
    TenantContext::forget();

    $this->admin = Admin::factory()->create();
    $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->admin->saveAppAuthenticationRecoveryCodes(['kode-1', 'kode-2']);

    $settings = app(SystemSettings::class);
    $settings->setTurnstileKeys('site-key', 'turnstile-secret');
    $settings->setBillingMidtrans('sandbox', ['sandbox' => 'SB-Mid-server-vendor']);

    // Produksi memakai session driver database
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => Str::random(40), 'payload' => 'x', 'last_activity' => time()]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function rotationKeyBytes(string $key): string
{
    return (string) base64_decode(Str::after($key, 'base64:'), true);
}

it('key baru di .env, data rahasia dienkripsi ulang & tetap terbaca, key lama dicabut, session diputus', function () {
    $before = DB::table('payment_gateway_settings')->value('server_key');

    $result = app(RotateAppKey::class)->handle($this->env);

    $newKey = (string) $this->env->get('APP_KEY');
    expect($newKey)->toStartWith('base64:')->not->toBe($this->oldKey)
        ->and($this->env->get('APP_PREVIOUS_KEYS'))->toBe('')
        ->and($this->env->get('APP_NAME'))->toBe('gs.POS')
        ->and(config('app.key'))->toBe($newKey)
        ->and(config('app.previous_keys'))->toBe([])
        // 2 key gateway + secret & recovery 2FA + secret Turnstile + key Midtrans vendor
        ->and($result['reencrypted'])->toBe(6)
        ->and($result['unreadable'])->toBe([])
        ->and($result['sessions'])->toBe(1)
        ->and(DB::table('sessions')->count())->toBe(0);

    // Hanya key baru yang bisa membaca; key lama tidak lagi
    $raw = DB::table('payment_gateway_settings')->value('server_key');
    $onlyNew = new Encrypter(rotationKeyBytes($newKey), (string) config('app.cipher'));
    $onlyOld = new Encrypter(rotationKeyBytes($this->oldKey), (string) config('app.cipher'));
    expect($raw)->not->toBe($before)
        ->and($onlyNew->decryptString($raw))->toBe('SB-Mid-server-abc')
        ->and(fn () => $onlyOld->decryptString($raw))->toThrow(DecryptException::class);

    // Aplikasi membaca nilai yang sama lewat cast & SystemSettings
    TenantContext::set($this->pos->tenantId);
    $gateway = PaymentGatewaySetting::current();
    $admin = Admin::query()->findOrFail($this->admin->id);
    $settings = app(SystemSettings::class);
    expect($gateway->server_key)->toBe('SB-Mid-server-abc')
        ->and($gateway->client_key)->toBe('SB-Mid-client-xyz')
        ->and($admin->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
        ->and($admin->getAppAuthenticationRecoveryCodes())->toBe(['kode-1', 'kode-2'])
        ->and($settings->turnstileSecretKey())->toBe('turnstile-secret')
        ->and($settings->billingMidtransKeySource('sandbox'))->toBe('saved');
});

it('--keep-previous: key lama tetap di APP_PREVIOUS_KEYS; aman diulang', function () {
    app(RotateAppKey::class)->handle($this->env, keepPrevious: true);
    $first = (string) $this->env->get('APP_KEY');
    expect($this->env->get('APP_PREVIOUS_KEYS'))->toBe($this->oldKey)
        ->and(config('app.previous_keys'))->toBe([$this->oldKey]);

    // Diulang (mis. terhenti di tengah lalu dijalankan lagi): semua key lama dikenali
    app(RotateAppKey::class)->handle($this->env);
    expect($this->env->get('APP_KEY'))->not->toBe($first)
        ->and($this->env->get('APP_PREVIOUS_KEYS'))->toBe('');

    TenantContext::set($this->pos->tenantId);
    expect(PaymentGatewaySetting::current()->server_key)->toBe('SB-Mid-server-abc')
        ->and(app(SystemSettings::class)->turnstileSecretKey())->toBe('turnstile-secret');
});

it('nilai yang sudah tidak terbaca dilaporkan & dibiarkan, data lain tetap dirotasi', function () {
    $garbage = (new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))->encryptString('kunci-entah');
    DB::table('payment_gateway_settings')->update(['client_key' => $garbage]);

    $result = app(RotateAppKey::class)->handle($this->env);

    expect($result['reencrypted'])->toBe(5)
        ->and($result['unreadable'])->toHaveCount(1)
        ->and($result['unreadable'][0])->toStartWith('payment_gateway_settings.client_key#')
        ->and(DB::table('payment_gateway_settings')->value('client_key'))->toBe($garbage);
});

it('command: tanpa --force minta konfirmasi; ditolak = .env tidak berubah', function () {
    $this->artisan('pos:rotate-key')
        ->expectsConfirmation('Ganti APP_KEY sekarang?', 'no')
        ->assertFailed();
    expect($this->env->get('APP_KEY'))->toBe($this->oldKey);

    $this->artisan('pos:rotate-key', ['--force' => true])
        ->expectsOutputToContain('6 data rahasia dienkripsi ulang')
        ->assertSuccessful();
    expect($this->env->get('APP_KEY'))->not->toBe($this->oldKey);
});

it('gagal aman bila .env tidak ada', function () {
    File::delete($this->dir.'/.env');

    $this->artisan('pos:rotate-key', ['--force' => true])
        ->expectsOutputToContain('File .env tidak ditemukan')
        ->assertFailed();
    expect(config('app.key'))->toBe($this->oldKey);
});
