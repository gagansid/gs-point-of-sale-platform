<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Installer\DatabaseProbe;
use App\Support\Installer\EnvironmentFile;
use App\Support\Installer\Installation;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

/*
 * Installer web /install (ADR 0016). Tiap test memakai folder sementara untuk .env, file kunci & status,
 * sehingga .env dan storage lokal tidak tersentuh. Koneksi database dipalsukan (DatabaseProbe), migrasi &
 * pembuatan akun berjalan di database test.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/gspos-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);
    app()->useEnvironmentPath($this->dir);

    config([
        'pos.installer.enabled' => true,
        'pos.installer.token' => null,
        'pos.installer.lock_path' => $this->dir.'/installed.json',
        'pos.installer.state_path' => $this->dir.'/install-state.json',
        'pos.installer.code_path' => $this->dir.'/install-code.txt',
        // Belum terinstal = belum ada APP_KEY (paket rilis baru diekstrak)
        'app.key' => '',
    ]);

    // Batas percobaan disimpan di cache file selama instal; jangan bocor antar test
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function fakeDatabaseProbe(object $test, bool $ok = true, string $message = 'Terhubung (MySQL 8.0), database kosong'): void
{
    $probe = Mockery::mock(DatabaseProbe::class);
    $probe->shouldReceive('check')->andReturn(['ok' => $ok, 'message' => $message]);
    app()->instance(DatabaseProbe::class, $probe);
}

/** Melewati form kode instal dengan kode acak yang dibuat installer di server. */
function passInstallCode(object $test): void
{
    $test->get('/install')->assertRedirect(route('install.token'));
    $test->post('/install/token', ['token' => (string) Installation::token()])->assertRedirect(route('install.requirements'));
}

/** Mengisi langkah form sampai halaman Instal. */
function fillInstallForms(object $test, array $application = [], array $accounts = []): void
{
    passInstallCode($test);
    fakeDatabaseProbe($test);

    $test->post('/install/requirements')->assertRedirect('/install/database');
    $test->post('/install/database', [
        'host' => 'localhost', 'port' => 3306, 'database' => 'user_gspos', 'username' => 'user_gspos',
        'password' => 'Rahasia#db pass', 'action' => 'next',
    ])->assertRedirect('/install/application');
    $test->post('/install/application', [
        'app_name' => 'gs.POS', 'app_url' => 'https://pos.contoh.id', 'edition' => 'self_hosted',
        'timezone' => 'Asia/Makassar', 'use_subdomains' => '0', 'app_domain' => 'app.127.0.0.1',
        ...$application,
    ])->assertSessionHasNoErrors()->assertRedirect('/install/accounts');
    $test->post('/install/accounts', [
        'admin_name' => 'Admin Utama', 'admin_email' => 'admin@contoh.id',
        'admin_password' => 'Rahasia-Admin#2026', 'admin_password_confirmation' => 'Rahasia-Admin#2026',
        'business_name' => 'Kopi Pagi', 'business_type' => 'cafe',
        'owner_name' => 'Rina Owner', 'owner_email' => 'rina@contoh.id',
        'owner_password' => 'owner-rahasia-1', 'owner_password_confirmation' => 'owner-rahasia-1',
        'create_manager' => '1', 'manager_name' => 'Budi Manager', 'manager_email' => 'budi@contoh.id',
        'manager_password' => 'manager-rahasia-1', 'manager_password_confirmation' => 'manager-rahasia-1',
        ...$accounts,
    ])->assertSessionHasNoErrors()->assertRedirect('/install/run');
}

it('format .env: polos, kutip tunggal untuk simbol, kutip ganda bila ada kutip tunggal; baris baru ditolak', function () {
    expect(EnvironmentFile::format('localhost'))->toBe('localhost')
        ->and(EnvironmentFile::format('pa ss#1'))->toBe("'pa ss#1'")
        ->and(EnvironmentFile::format("Kopi Uji's"))->toBe('"Kopi Uji\'s"')
        ->and(EnvironmentFile::format(true))->toBe('true')
        ->and(EnvironmentFile::format(null))->toBe('')
        ->and(fn () => EnvironmentFile::format("a\nB=1"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => EnvironmentFile::format("it's \$x"))->toThrow(InvalidArgumentException::class);

    $env = new EnvironmentFile($this->dir.'/.env');
    $env->createFrom(base_path('.env.example'));
    $env->set(['DB_PASSWORD' => 'a#b c', 'NEW_KEY' => 'x']);

    expect($env->get('DB_PASSWORD'))->toBe('a#b c')->and($env->get('NEW_KEY'))->toBe('x')
        ->and(substr_count((string) file_get_contents($this->dir.'/.env'), 'DB_PASSWORD='))->toBe(1)
        ->and(decoct(fileperms($this->dir.'/.env') & 0777))->toBe('640');
});

it('belum terinstal: web dialihkan ke /install, API 503, .env + APP_KEY dibuat dalam mode production', function () {
    $this->get('/')->assertRedirect('/install');
    $this->getJson('/api/v1/system/status')->assertStatus(503)->assertJsonPath('error.code', 'MAINTENANCE');

    $env = new EnvironmentFile($this->dir.'/.env');
    expect($env->get('APP_KEY'))->toStartWith('base64:')
        ->and($env->get('APP_ENV'))->toBe('production')
        ->and($env->get('APP_DEBUG'))->toBe('false')
        ->and(Installation::inProgress())->toBeTrue();

    $this->get('/install')->assertRedirect(route('install.token'));
    passInstallCode($this);
    $this->get('/install/requirements')->assertOk()->assertSee('Cek syarat server');
});

it('.env berisi INSTALL_TOKEN saja dilengkapi dari .env.example tanpa menghapus kode', function () {
    file_put_contents($this->dir.'/.env', "INSTALL_TOKEN=kode-rahasia-123\n");

    $this->get('/');

    $env = new EnvironmentFile($this->dir.'/.env');
    expect($env->get('INSTALL_TOKEN'))->toBe('kode-rahasia-123')
        ->and($env->get('SESSION_ENCRYPT'))->toBe('true')
        ->and($env->get('APP_ENV'))->toBe('production')
        ->and($env->get('APP_KEY'))->toStartWith('base64:');
});

it('sudah terinstal: /install 404; instalasi lama (APP_KEY ada, tanpa status) dikunci otomatis', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->get('/install')->assertNotFound();
    $this->post('/install/run/finish')->assertNotFound();
    expect(is_file($this->dir.'/installed.json'))->toBeTrue();
});

it('INSTALL_TOKEN diisi: kode itu yang diminta (peka huruf), tanpa file kode acak', function () {
    config(['pos.installer.token' => 'kode-rahasia-123']);

    $this->get('/install/requirements')->assertRedirect(route('install.token'));
    $this->get('/install/token')->assertOk()->assertSee('INSTALL_TOKEN');
    $this->post('/install/token', ['token' => 'salah'])->assertSessionHasErrors('token');
    $this->post('/install/token', ['token' => 'KODE-RAHASIA-123'])->assertSessionHasErrors('token');
    $this->post('/install/token', ['token' => 'kode-rahasia-123'])->assertRedirect(route('install.requirements'));
    $this->get('/install/requirements')->assertOk();

    expect(is_file($this->dir.'/install-code.txt'))->toBeFalse();
});

it('GSPOS-SEC-026: tanpa INSTALL_TOKEN installer tetap terkunci kode acak di file server', function () {
    // Pengunjung tanpa akses server: semua langkah & proses instal dialihkan ke form kode
    foreach (['/install/requirements', '/install/database', '/install/application', '/install/accounts', '/install/run'] as $page) {
        $this->get($page)->assertRedirect(route('install.token'));
    }
    $this->postJson('/install/run/environment')->assertStatus(409);
    $this->post('/install/database', ['host' => '10.0.0.5', 'port' => 3306, 'database' => 'db', 'username' => 'u', 'password' => 'p', 'action' => 'test'])
        ->assertRedirect(route('install.token'));

    $code = (string) file_get_contents($this->dir.'/install-code.txt');
    expect(trim($code))->toMatch('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/')
        ->and(fileperms($this->dir.'/install-code.txt') & 0777)->toBe(0600);

    // Form menunjuk lokasi file, bukan isinya
    $this->get('/install/token')->assertOk()->assertSee('install-code.txt')->assertDontSee(trim($code));

    $this->post('/install/token', ['token' => ''])->assertSessionHasErrors('token');
    $this->post('/install/token', ['token' => 'AAAA-BBBB-CCCC'])->assertSessionHasErrors('token');
    // Tidak peka huruf besar/kecil & spasi di tepi
    $this->post('/install/token', ['token' => '  '.strtolower(trim($code)).' '])->assertRedirect(route('install.requirements'));
    $this->get('/install/requirements')->assertOk();

    // Kode tetap sama selama instal belum selesai
    $this->get('/install');
    expect((string) file_get_contents($this->dir.'/install-code.txt'))->toBe($code);
});

it('kode instal hanya cocok bila ada kode yang berlaku (gagal tertutup)', function () {
    expect(Installation::token())->toBeNull()
        ->and(Installation::tokenMatches(''))->toBeFalse()
        ->and(Installation::tokenMatches('apa saja'))->toBeFalse();
});

it('kata sandi database yang tidak bisa ditulis aman ke .env ditolak di form', function () {
    passInstallCode($this);
    $this->post('/install/requirements');
    fakeDatabaseProbe($this);

    $this->post('/install/database', ['host' => 'localhost', 'port' => 3306, 'database' => 'db', 'username' => 'u', 'password' => "it's \$x", 'action' => 'next'])
        ->assertSessionHasErrors('password');
});

it('langkah tidak bisa dilompati; database tidak kosong ditolak dengan pesan', function () {
    passInstallCode($this);
    $this->get('/install/accounts')->assertRedirect(route('install.requirements'));
    $this->get('/install/run')->assertRedirect(route('install.requirements'));

    $this->post('/install/requirements');
    fakeDatabaseProbe($this, false, 'Database tidak kosong (12 tabel).');

    $this->post('/install/database', ['host' => 'localhost', 'port' => 3306, 'database' => 'db', 'username' => 'u', 'password' => 'p', 'action' => 'test'])
        ->assertSessionHasErrors(['connection' => 'Database tidak kosong (12 tabel).']);
    $this->get('/install/application')->assertRedirect(route('install.database'));
});

it('validasi akun: kata sandi super admin lemah & email manager sama dengan owner ditolak', function () {
    fillInstallForms($this);

    $this->post('/install/accounts', [
        'admin_name' => 'A', 'admin_email' => 'admin@contoh.id', 'admin_password' => 'pendek1', 'admin_password_confirmation' => 'pendek1',
        'business_name' => 'Kopi', 'business_type' => 'cafe', 'owner_name' => 'O', 'owner_email' => 'sama@contoh.id',
        'owner_password' => 'owner-rahasia-1', 'owner_password_confirmation' => 'owner-rahasia-1',
        'create_manager' => '1', 'manager_name' => 'M', 'manager_email' => 'sama@contoh.id',
        'manager_password' => 'manager-rahasia-1', 'manager_password_confirmation' => 'manager-rahasia-1',
    ])->assertSessionHasErrors(['admin_password', 'manager_email']);

    // Jual putus: bisnis wajib walau centang dikirim 0
    $this->post('/install/accounts', [
        'admin_name' => 'A', 'admin_email' => 'admin@contoh.id',
        'admin_password' => 'Rahasia-Admin#2026', 'admin_password_confirmation' => 'Rahasia-Admin#2026',
        'create_business' => '0',
    ])->assertSessionHasErrors(['business_name', 'owner_email', 'owner_password']);
});

it('proses instal: .env final, migrasi, akun & bisnis, kunci installer, lalu ke halaman login', function () {
    fillInstallForms($this);

    $this->get('/install/run')->assertOk()->assertSee('Kopi Pagi')->assertSee('admin@contoh.id')->assertDontSee('Rahasia#db pass');

    // Urutan tidak bisa dilompati
    $this->postJson('/install/run/accounts')->assertStatus(409);

    $this->postJson('/install/run/environment')->assertOk()->assertJson(['ok' => true, 'done' => true]);
    $env = new EnvironmentFile($this->dir.'/.env');
    expect($env->get('DB_PASSWORD'))->toBe('Rahasia#db pass')
        ->and($env->get('POS_EDITION'))->toBe('self_hosted')
        ->and($env->get('POS_DEFAULT_TIMEZONE'))->toBe('Asia/Makassar')
        ->and($env->get('POS_APP_DOMAIN'))->toBe('')
        ->and($env->get('SESSION_SECURE_COOKIE'))->toBe('true')
        ->and($env->get('MAIL_MAILER'))->toBe('log')
        ->and($env->get('MAIL_FROM_ADDRESS'))->toBe('no-reply@pos.contoh.id');

    do {
        $migrate = $this->postJson('/install/run/migrate')->assertOk()->json();
    } while (! $migrate['done']);

    $this->postJson('/install/run/accounts')->assertOk()->assertJson(['ok' => true, 'done' => true]);
    // Diulang (mis. refresh): tidak membuat data ganda
    $this->postJson('/install/run/accounts')->assertOk();

    expect(Admin::query()->where('email', 'admin@contoh.id')->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe(1);

    $tenant = Tenant::query()->firstOrFail();
    $users = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->keyBy('email');
    expect($tenant->name)->toBe('Kopi Pagi')
        ->and($users['rina@contoh.id']->role)->toBe(UserRole::Owner)
        ->and($users['rina@contoh.id']->email_verified_at)->not->toBeNull()
        ->and($users['budi@contoh.id']->role)->toBe(UserRole::Manager)
        ->and($users['budi@contoh.id']->email_verified_at)->toBeNull();

    $finish = $this->postJson('/install/run/finish')->assertOk()->json();
    expect($finish['redirect'])->toContain('login')
        ->and(is_file($this->dir.'/installed.json'))->toBeTrue()
        ->and(is_file($this->dir.'/install-code.txt'))->toBeFalse()
        ->and(Installation::inProgress())->toBeFalse();

    $this->get('/install')->assertNotFound();
});

it('SaaS tanpa bisnis: hanya super admin, selesai diarahkan ke login panel admin', function () {
    fillInstallForms($this, ['edition' => 'saas'], ['create_business' => '0', 'create_manager' => '0']);

    foreach (['environment', 'migrate', 'accounts'] as $step) {
        do {
            $body = $this->postJson("/install/run/{$step}")->assertOk()->json();
        } while (! $body['done']);
    }

    expect(Admin::query()->count())->toBe(1)->and(Tenant::query()->count())->toBe(0);
    expect($this->postJson('/install/run/finish')->json('redirect'))->toContain('/admin/login');
});
