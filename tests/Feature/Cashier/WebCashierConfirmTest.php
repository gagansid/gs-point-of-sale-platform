<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;

/** Menu Kasir di dashboard → masuk ulang kasir web dengan kata sandi atau PIN (SPEC Q55). */
beforeEach(function () {
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang']);
    TenantContext::set($this->outlet->tenant_id);
    $this->owner = User::factory()->owner()->create(['password' => 'rahasia-123']);
    $this->manager = User::factory()->manager()->forOutlet($this->outlet)
        ->create(['password' => 'rahasia-123', 'web_cashier_access' => false]);
    TenantContext::forget();
});

function confirmCashier(object $test, array $body): TestResponse
{
    return $test->post(route('cashier.confirm.store'), ['method' => 'password', 'password' => 'rahasia-123', ...$body]);
}

it('tanpa sesi dashboard diarahkan ke login kasir biasa', function () {
    $this->get(route('cashier.confirm'))->assertRedirect(route('cashier.login'));
    confirmCashier($this, [])->assertRedirect(route('cashier.login'));
    $this->assertGuest(WebCashier::GUARD);
});

it('owner masuk ulang dengan kata sandi; sesi dashboard tetap login', function () {
    $this->actingAs($this->owner, 'web');

    $this->get(route('cashier.confirm'))->assertOk()
        ->assertSee('Masuk sebagai')->assertSee($this->owner->name)
        ->assertSee('value="pin"', false)
        ->assertSee('v'.config('app.version'))
        ->assertDontSee('Lupa')
        // Tab cara masuk dipilih di form, bukan lewat URL
        ->assertDontSee('confirm?method', false);

    confirmCashier($this, [])->assertRedirect(route('cashier.outlet'));

    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
    $this->assertAuthenticatedAs($this->owner, 'web');
    expect($this->owner->fresh()?->last_login_at)->not->toBeNull();
});

it('owner masuk ulang dengan PIN', function () {
    $this->actingAs($this->owner, 'web');

    // Punya PIN → tab PIN terpilih lebih dulu
    $this->get(route('cashier.confirm'))->assertOk()->assertSee('value="pin" checked', false);
    confirmCashier($this, ['method' => 'pin', 'pin' => '123456'])->assertRedirect(route('cashier.outlet'));

    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
});

it('sudah masuk kasir: halaman konfirmasi langsung ke kasir', function () {
    $this->actingAs($this->owner, 'web');
    confirmCashier($this, []);
    confirmCashier($this, []);

    $this->get(route('cashier.confirm'))->assertRedirect(route('cashier.home'));
    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
});

it('kata sandi atau PIN salah ditolak', function (array $body, array $message) {
    $this->actingAs($this->owner, 'web');

    confirmCashier($this, $body)->assertSessionHasErrors($message);
    $this->assertGuest(WebCashier::GUARD);
})->with([
    'kata sandi salah' => [['password' => 'salah-sekali'], ['password' => 'Kata sandi salah.']],
    'PIN salah' => [['method' => 'pin', 'pin' => '000000'], ['pin' => 'PIN salah']],
]);

it('validasi: PIN harus 6 digit dan wajib diisi', function (array $body, string $field) {
    $this->actingAs($this->owner, 'web');

    confirmCashier($this, $body)->assertSessionHasErrors($field);
    $this->assertGuest(WebCashier::GUARD);
})->with([
    'kata sandi kosong' => [['password' => ''], 'password'],
    'PIN kosong' => [['method' => 'pin', 'pin' => ''], 'pin'],
    'PIN huruf' => [['method' => 'pin', 'pin' => 'abcdef'], 'pin'],
    'PIN pendek' => [['method' => 'pin', 'pin' => '1234'], 'pin'],
    'cara masuk tidak dikenal' => [['method' => 'face'], 'method'],
]);

it('PIN salah berulang mengunci PIN', function () {
    $this->actingAs($this->owner, 'web');

    foreach (range(1, 5) as $i) {
        confirmCashier($this, ['method' => 'pin', 'pin' => '000000']);
    }

    // PIN benar pun ditolak selama terkunci
    confirmCashier($this, ['method' => 'pin', 'pin' => '123456'])->assertSessionHasErrors('pin');
    expect(session('errors')->first('pin'))->toContain('terkunci');
    $this->assertGuest(WebCashier::GUARD);
});

it('kata sandi salah berulang dibatasi', function () {
    $this->actingAs($this->owner, 'web');

    foreach (range(1, 5) as $i) {
        confirmCashier($this, ['password' => 'salah-sekali']);
    }

    confirmCashier($this, [])->assertSessionHasErrors('password');
    expect(session('errors')->first('password'))->toContain('Terlalu banyak percobaan');
    $this->assertGuest(WebCashier::GUARD);
});

it('user tanpa PIN: tab PIN disembunyikan dan login PIN ditolak', function () {
    $this->owner->forceFill(['pin' => null])->save();
    $this->actingAs($this->owner, 'web');

    $this->get(route('cashier.confirm'))->assertOk()
        ->assertSee('PIN atau kata sandi terkunci? Hubungi owner.')
        ->assertDontSee('inputmode="numeric"', false);

    confirmCashier($this, ['method' => 'pin', 'pin' => '123456'])->assertSessionHasErrors('pin');
    $this->assertGuest(WebCashier::GUARD);
});

it('permission: manager tanpa akses kasir web ditolak walau kata sandi benar', function () {
    $this->actingAs($this->manager, 'web');

    confirmCashier($this, [])->assertSessionHasErrors(['login' => 'Akun Anda belum diberi akses kasir web. Hubungi owner.']);
    $this->assertGuest(WebCashier::GUARD);
});

it('identitas selalu dari sesi dashboard: field user lain diabaikan (isolasi tenant)', function () {
    $other = Outlet::factory()->create();
    TenantContext::set($other->tenant_id);
    $otherOwner = User::factory()->owner()->create(['password' => 'rahasia-123']);
    TenantContext::forget();

    $this->actingAs($this->owner, 'web');
    confirmCashier($this, ['user_id' => $otherOwner->id, 'login' => $otherOwner->email])->assertRedirect(route('cashier.outlet'));

    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
    expect(WebCashier::user()?->tenant_id)->toBe($this->outlet->tenant_id);
});

it('langganan tidak aktif ditolak', function () {
    Tenant::query()->whereKey($this->outlet->tenant_id)->update(['status' => 'suspended']);
    $this->actingAs($this->owner, 'web');

    confirmCashier($this, [])->assertSessionHasErrors('login');
    $this->assertGuest(WebCashier::GUARD);
});

it('menu Kasir di sidebar dibuka di tab baru untuk user dengan akses kasir web', function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->owner, 'web');

    $this->get(Filament::getPanel('dashboard')->getUrl())->assertOk()
        ->assertSee(route('cashier.confirm'), false)->assertSee('target="_blank"', false);
});

it('menu Kasir tersembunyi untuk manager tanpa akses kasir web', function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->manager, 'web');

    $this->get(Filament::getPanel('dashboard')->getUrl())->assertOk()->assertDontSee(route('cashier.confirm'), false);
});

it('keluar dari kasir kembali ke dashboard bila sesi dashboard aktif', function () {
    $this->actingAs($this->owner, 'web');
    confirmCashier($this, []);

    $this->post(route('cashier.logout'))->assertRedirect(Filament::getPanel('dashboard')->getUrl());
    $this->assertGuest(WebCashier::GUARD);
    $this->assertAuthenticatedAs($this->owner, 'web');
});
