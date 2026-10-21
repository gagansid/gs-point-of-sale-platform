<?php

declare(strict_types=1);

use App\Enums\AppPlatform;
use App\Enums\TenantStatus;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/** Login kasir web /cashier, pilih outlet & device browser (SPEC Q54). */
beforeEach(function () {
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang']);
    $this->tenant = Tenant::query()->findOrFail($this->outlet->tenant_id);
    $this->tenant->forceFill(['slug' => 'kopi-senja'])->save();
    TenantContext::set($this->tenant->id);
    $this->owner = User::factory()->owner()->create(['email' => 'owner@kopi.test', 'password' => 'rahasia-123']);
    $this->budi = User::factory()->cashier()->forOutlet($this->outlet)
        ->create(['username' => 'budi', 'password' => 'rahasia-123', 'web_cashier_access' => true]);
    TenantContext::forget();
});

function cashierLogin(object $test, array $body): TestResponse
{
    return $test->post(route('cashier.login.store'), [
        'mode' => 'username', 'business' => 'kopi-senja', 'login' => 'budi', 'password' => 'rahasia-123', ...$body,
    ]);
}

function webDevices(): Collection
{
    return Device::allTenants()->where('platform', AppPlatform::Web->value)->get();
}

it('tamu diarahkan ke login kasir web', function () {
    $this->get(route('cashier.home'))->assertRedirect(route('cashier.login'));
    $this->get(route('cashier.login'))->assertOk()->assertSee('Masuk ke kasir')
        ->assertSee('v'.config('app.version'))
        // Tab cara masuk dipilih di form, bukan lewat URL
        ->assertDontSee('?mode=', false);
});

it('kasir: kode bisnis + username → satu outlet langsung terpilih, browser jadi device web', function () {
    cashierLogin($this, ['business' => ' KOPI-Senja ', 'login' => 'BUDI'])->assertRedirect(route('cashier.outlet'));
    $this->assertAuthenticatedAs($this->budi, WebCashier::GUARD);
    // Sesi /dashboard tidak ikut login
    $this->assertGuest('web');

    $this->get(route('cashier.outlet'))->assertRedirect(route('cashier.home'));
    // Belum ada shift → layar transaksi mengarahkan ke buka shift
    $this->get(route('cashier.home'))->assertRedirect(route('cashier.shift'));
    $this->get(route('cashier.shift'))->assertOk()->assertSee('Kemang')->assertSee('Buka shift');

    $device = webDevices()->sole();
    expect($device->outlet_id)->toBe($this->outlet->id)
        ->and($device->tenant_id)->toBe($this->tenant->id)
        ->and($device->device_uid)->toStartWith('web:')
        ->and($this->budi->fresh()?->last_login_at)->not->toBeNull();
});

it('browser yang sama login lagi memakai device yang sama', function () {
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'));
    $browserId = explode(':', webDevices()->sole()->device_uid)[1];
    $this->post(route('cashier.logout'))->assertRedirect(route('cashier.login'));
    $this->assertGuest(WebCashier::GUARD);

    $this->withCookie(WebCashier::BROWSER_COOKIE, $browserId);
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'))->assertRedirect(route('cashier.home'));

    expect(webDevices())->toHaveCount(1);
});

it('cookie browser hilang (browser ditutup/privat): login lagi melanjutkan shift web yang masih terbuka', function () {
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'));
    $this->post(route('cashier.shift.store'), ['opening_cash' => '150000'])->assertRedirect(route('cashier.home'));
    $device = webDevices()->sole();

    // Browser ditutup: sesi & cookie browser hilang (request uji tidak membawa cookie respons sebelumnya)
    $this->flushSession();
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'))->assertRedirect(route('cashier.home'))
        // Browser ini memakai device shift tadi; login berikutnya tidak perlu pencarian ulang
        ->assertCookie(WebCashier::BROWSER_COOKIE, explode(':', $device->device_uid)[1]);
    $this->get(route('cashier.home'))->assertOk();

    expect(webDevices())->toHaveCount(1)
        ->and(Shift::allTenants()->whereNotNull('open_device_key')->count())->toBe(1);
});

it('shift web yang dipegang kasir lain tidak dilanjutkan dari browser baru', function () {
    $sari = TenantContext::run($this->tenant->id, fn () => User::factory()->supervisor()->forOutlet($this->outlet)
        ->create(['username' => 'sari', 'password' => 'rahasia-123', 'web_cashier_access' => true]));
    cashierLogin($this, ['login' => 'sari']);
    $this->get(route('cashier.outlet'));
    $this->post(route('cashier.shift.store'), ['opening_cash' => '150000'])->assertRedirect(route('cashier.home'));

    $this->flushSession();
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'))->assertRedirect(route('cashier.home'));
    $this->get(route('cashier.home'))->assertRedirect(route('cashier.shift'));

    expect(webDevices())->toHaveCount(2);
});

it('owner login dengan email; boleh walau kolom akses nonaktif', function () {
    $this->post(route('cashier.login.store'), ['mode' => 'email', 'login' => 'OWNER@kopi.test', 'password' => 'rahasia-123'])
        ->assertRedirect(route('cashier.outlet'));
    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
});

it('pesan umum untuk kata sandi salah, username/kode bisnis tidak dikenal', function (array $body) {
    cashierLogin($this, $body)->assertSessionHasErrors(['login' => 'Akun atau kata sandi salah']);
    $this->assertGuest(WebCashier::GUARD);
})->with([
    'kata sandi salah' => [['password' => 'salah-123']],
    'username tidak ada' => [['login' => 'siapa']],
    'kode bisnis salah' => [['business' => 'lain']],
    'mode email dengan username' => [['mode' => 'email', 'login' => 'budi']],
]);

it('validasi: kode bisnis wajib untuk mode username', function () {
    cashierLogin($this, ['business' => ''])->assertSessionHasErrors(['business']);
});

it('tanpa akses kasir web: ditolak dengan pesan khusus setelah kata sandi benar', function () {
    $this->budi->forceFill(['web_cashier_access' => false])->save();

    cashierLogin($this, [])->assertSessionHasErrors(['login' => 'Akun Anda belum diberi akses kasir web. Hubungi owner.']);
    cashierLogin($this, ['password' => 'salah-123'])->assertSessionHasErrors(['login' => 'Akun atau kata sandi salah']);
    $this->assertGuest(WebCashier::GUARD);
});

it('bisnis diblokir tidak bisa login', function () {
    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    cashierLogin($this, [])->assertSessionHasErrors(['login']);
    $this->assertGuest(WebCashier::GUARD);
});

it('isolasi tenant: username tenant lain dengan kode bisnis ini gagal', function () {
    $other = Outlet::factory()->create();
    TenantContext::run($other->tenant_id, fn () => User::factory()->cashier()->forOutlet($other)
        ->create(['username' => 'sari', 'password' => 'rahasia-123', 'web_cashier_access' => true]));

    cashierLogin($this, ['login' => 'sari'])->assertSessionHasErrors(['login' => 'Akun atau kata sandi salah']);
});

it('throttle: 5 percobaan gagal per menit', function () {
    RateLimiter::clear('cashier-login:kopi-senja|budi|127.0.0.1');
    foreach (range(1, 5) as $i) {
        cashierLogin($this, ['password' => 'salah-123']);
    }

    cashierLogin($this, [])->assertSessionHasErrors('login');
    $this->assertGuest(WebCashier::GUARD);
});

it('multi outlet: pilih hanya outlet yang ditugaskan; outlet lain 404', function () {
    $cilandak = Outlet::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cilandak']);
    $unassigned = Outlet::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bintaro']);
    $this->budi->outlets()->attach($cilandak->id);

    cashierLogin($this, []);
    $this->get(route('cashier.home'))->assertRedirect(route('cashier.outlet'));
    $this->get(route('cashier.outlet'))->assertOk()->assertSee('Kemang')->assertSee('Cilandak')->assertDontSee('Bintaro');

    $this->post(route('cashier.outlet.store'), ['outlet_id' => $unassigned->id])->assertNotFound();
    $this->post(route('cashier.outlet.store'), ['outlet_id' => Outlet::factory()->create()->id])->assertNotFound();

    $this->post(route('cashier.outlet.store'), ['outlet_id' => $cilandak->id])->assertRedirect(route('cashier.home'));
    $this->get(route('cashier.shift'))->assertOk()->assertSee('Cilandak');
    expect(webDevices()->sole()->outlet_id)->toBe($cilandak->id);
});

it('pilih outlet: maks. 3 outlet tampil sebagai daftar urut nama', function () {
    Outlet::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bintaro']);

    $this->post(route('cashier.login.store'), ['mode' => 'email', 'login' => 'owner@kopi.test', 'password' => 'rahasia-123']);

    $this->get(route('cashier.outlet'))->assertOk()
        ->assertSeeInOrder(['Bintaro', 'Kemang'])
        ->assertDontSee('Cari nama atau kode outlet');
});

it('pilih outlet: lebih dari 3 outlet tampil sebagai dropdown dengan pencarian', function () {
    foreach (['Bintaro', 'Cilandak', 'Depok'] as $name) {
        Outlet::factory()->create(['tenant_id' => $this->tenant->id, 'name' => $name]);
    }

    $this->post(route('cashier.login.store'), ['mode' => 'email', 'login' => 'owner@kopi.test', 'password' => 'rahasia-123']);

    $this->get(route('cashier.outlet'))->assertOk()
        ->assertSee('data-outlet-select', false)
        ->assertSee('Cari nama atau kode outlet')
        ->assertSee('4 outlet')
        ->assertSeeInOrder(['Bintaro', 'Cilandak', 'Depok', 'Kemang']);
});

it('akses dicabut, akun nonaktif, atau kata sandi diganti → sesi kasir web keluar', function (Closure $change) {
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'));
    $this->get(route('cashier.shift'))->assertOk();

    $change($this->budi);
    Auth::forgetGuards();

    $this->get(route('cashier.home'))->assertRedirect(route('cashier.login'));
    $this->assertGuest(WebCashier::GUARD);
})->with([
    'akses dicabut' => [fn (User $u) => $u->forceFill(['web_cashier_access' => false])->save()],
    'nonaktif' => [fn (User $u) => $u->forceFill(['is_active' => false])->save()],
    'kata sandi diganti' => [fn (User $u) => $u->forceFill(['password' => 'baru-12345'])->save()],
]);

it('device browser dicabut owner → kembali ke pilih outlet dengan pesan', function () {
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'));
    $device = webDevices()->sole();
    $device->forceFill(['revoked_at' => now()])->save();
    $this->withCookie(WebCashier::BROWSER_COOKIE, explode(':', $device->device_uid)[1]);

    $this->get(route('cashier.home'))->assertRedirect(route('cashier.outlet'));
    $this->get(route('cashier.outlet'))->assertOk()->assertSee('tidak bisa dipakai lagi');
    $this->post(route('cashier.outlet.store'), ['outlet_id' => $this->outlet->id])
        ->assertRedirect(route('cashier.outlet'))->assertSessionHasErrors('outlet_id');
});

it('logout kasir web tidak mengeluarkan sesi dashboard', function () {
    $this->actingAs($this->owner, 'web');
    cashierLogin($this, []);

    $this->post(route('cashier.logout'));
    $this->assertGuest(WebCashier::GUARD);
    $this->assertAuthenticatedAs($this->owner, 'web');
});

it('pesan kasir tampil sebagai toast kanan atas, bukan alert di dalam halaman', function () {
    cashierLogin($this, []);
    $this->get(route('cashier.outlet'));

    $this->post(route('cashier.shift.store'), ['opening_cash' => '100000'])->assertRedirect();
    $this->followingRedirects()->get(route('cashier.home'))->assertOk()
        ->assertSee('data-toasts', false)
        ->assertSeeInOrder(['window.cashierToast(', 'Shift berhasil dibuka', "'success'"], false)
        ->assertDontSee('site-alert-success', false);
});

it('pesan validasi tidak menyebut nama field internal (mode/username)', function () {
    cashierLogin($this, ['business' => ''])->assertSessionHasErrors(['business' => 'Masukkan kode bisnis Anda']);

    $errors = session('errors')->getBag('default')->all();
    expect(implode(' ', $errors))->not->toContain('mode')->not->toContain('bernilai');
});
