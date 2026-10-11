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
    return $test->post(route('cashier.confirm.store'), ['method' => 'password', 'secret' => 'rahasia-123', ...$body]);
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
        ->assertSee('PIN')
        ->assertDontSee('Lupa');

    confirmCashier($this, [])->assertRedirect(route('cashier.outlet'));

    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
    $this->assertAuthenticatedAs($this->owner, 'web');
    expect($this->owner->fresh()?->last_login_at)->not->toBeNull();
});

it('owner masuk ulang dengan PIN', function () {
    $this->actingAs($this->owner, 'web');

    $this->get(route('cashier.confirm', ['method' => 'pin']))->assertOk()->assertSee('inputmode="numeric"', false);
    confirmCashier($this, ['method' => 'pin', 'secret' => '123456'])->assertRedirect(route('cashier.outlet'));

    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
});

it('sudah masuk kasir: halaman konfirmasi langsung ke kasir', function () {
    $this->actingAs($this->owner, 'web');
    confirmCashier($this, []);
    confirmCashier($this, []);

    $this->get(route('cashier.confirm'))->assertRedirect(route('cashier.home'));
    $this->assertAuthenticatedAs($this->owner, WebCashier::GUARD);
});

it('kata sandi atau PIN salah ditolak', function (array $body, string $message) {
    $this->actingAs($this->owner, 'web');

    confirmCashier($this, $body)->assertSessionHasErrors(['secret' => $message]);
    $this->assertGuest(WebCashier::GUARD);
})->with([
    'kata sandi salah' => [['secret' => 'salah-sekali'], 'Kata sandi salah.'],
    'PIN salah' => [['method' => 'pin', 'secret' => '000000'], 'PIN salah'],
]);

it('validasi: PIN harus 6 digit dan wajib diisi', function (array $body) {
    $this->actingAs($this->owner, 'web');

    confirmCashier($this, $body)->assertSessionHasErrors('secret');
    $this->assertGuest(WebCashier::GUARD);
})->with([
    'kosong' => [['secret' => '']],
    'PIN huruf' => [['method' => 'pin', 'secret' => 'abcdef']],
    'PIN pendek' => [['method' => 'pin', 'secret' => '1234']],
]);

it('PIN salah berulang mengunci PIN', function () {
    $this->actingAs($this->owner, 'web');

    foreach (range(1, 5) as $i) {
        confirmCashier($this, ['method' => 'pin', 'secret' => '000000']);
    }

    // PIN benar pun ditolak selama terkunci
    confirmCashier($this, ['method' => 'pin', 'secret' => '123456'])->assertSessionHasErrors('secret');
    expect(session('errors')->first('secret'))->toContain('terkunci');
    $this->assertGuest(WebCashier::GUARD);
});

it('kata sandi salah berulang dibatasi', function () {
    $this->actingAs($this->owner, 'web');

    foreach (range(1, 5) as $i) {
        confirmCashier($this, ['secret' => 'salah-sekali']);
    }

    confirmCashier($this, [])->assertSessionHasErrors('secret');
    expect(session('errors')->first('secret'))->toContain('Terlalu banyak percobaan');
    $this->assertGuest(WebCashier::GUARD);
});

it('user tanpa PIN: tab PIN disembunyikan dan login PIN ditolak', function () {
    $this->owner->forceFill(['pin' => null])->save();
    $this->actingAs($this->owner, 'web');

    $this->get(route('cashier.confirm', ['method' => 'pin']))->assertOk()
        ->assertSee('PIN belum diatur')
        ->assertDontSee('inputmode="numeric"', false);

    confirmCashier($this, ['method' => 'pin', 'secret' => '123456'])->assertSessionHasErrors('secret');
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
