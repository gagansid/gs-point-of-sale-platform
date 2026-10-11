<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\ChangeMemberPassword;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\PaymentCategory;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Member\MemberProof;
use App\Support\CurrentOutlet;
use App\Support\MemberPortal;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/** Halaman member gspos.id/m/{bisnis} — M4 (ADR 0013, Q67). */
beforeEach(function () {
    $this->pos = posSetup();
    Tenant::query()->whereKey($this->pos->tenantId)->update(['slug' => 'kopi-senja']);
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->member = Member::factory()->create(['name' => 'Rina', 'phone' => '+6281234567890']);
    $this->member->forceFill(['password' => 'rahasia-123'])->save();
    TenantContext::forget();
});

function memberLogin(object $test, string $phone = '0812-3456-7890', string $password = 'rahasia-123')
{
    return $test->post('/m/kopi-senja/login', ['phone' => $phone, 'password' => $password]);
}

it('masuk dengan nomor HP (format apa pun) + kata sandi → beranda dengan QR & saldo', function () {
    memberLogin($this)->assertRedirect('/m/kopi-senja');
    $this->assertAuthenticatedAs($this->member, MemberPortal::GUARD);

    $this->get('/m/kopi-senja')->assertOk()
        ->assertSee('Rina')->assertSee('poin')
        ->assertSee($this->member->code.'-', false)
        ->assertSee('<svg', false);
});

it('pesan umum untuk HP tidak terdaftar & kata sandi salah; dibatasi 5/menit', function () {
    memberLogin($this, '0899 0000 1111')->assertSessionHasErrors(['phone' => 'Nomor HP atau kata sandi salah']);
    memberLogin($this, password: 'salah-123')->assertSessionHasErrors(['phone' => 'Nomor HP atau kata sandi salah']);

    foreach (range(1, 4) as $i) {
        memberLogin($this, password: 'salah-123');
    }
    memberLogin($this)->assertSessionHasErrors('phone');
    $this->assertGuest(MemberPortal::GUARD);
});

it('isolasi tenant: member bisnis lain tidak bisa masuk di kode bisnis ini; sesi tidak berlaku lintas bisnis', function () {
    $other = posSetup();
    Tenant::query()->whereKey($other->tenantId)->update(['slug' => 'kopi-lain']);
    TenantContext::run($other->tenantId, fn () => app(UpdateMemberProgram::class)->handle(true, [$other->outlet->id]));

    $this->post('/m/kopi-lain/login', ['phone' => '081234567890', 'password' => 'rahasia-123'])->assertSessionHasErrors('phone');

    memberLogin($this);
    $this->get('/m/kopi-lain')->assertRedirect('/m/kopi-lain/login');
});

it('bisnis tanpa program member / tidak dikenal → 404', function () {
    TenantContext::run($this->pos->tenantId, fn () => app(UpdateMemberProgram::class)->handle(false, []));

    $this->get('/m/kopi-senja/login')->assertNotFound();
    $this->get('/m/tidak-ada/login')->assertNotFound();
});

it('daftar mandiri: persetujuan & kata sandi kuat wajib; nomor yang sudah ada tidak bisa diklaim', function () {
    $this->post('/m/kopi-senja/register', ['name' => 'Budi', 'phone' => '0812 3456 7890', 'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123', 'consent' => '1'])
        ->assertSessionHasErrors('phone');
    $this->post('/m/kopi-senja/register', ['name' => 'Budi', 'phone' => '0812 9999 0000', 'password' => 'pendek', 'password_confirmation' => 'pendek'])
        ->assertSessionHasErrors(['password', 'consent']);

    $this->post('/m/kopi-senja/register', ['name' => 'Budi', 'phone' => '0812 9999 0000', 'password' => 'rahasia-456', 'password_confirmation' => 'rahasia-456', 'consent' => '1'])
        ->assertRedirect('/m/kopi-senja');

    $budi = Member::allTenants()->where('phone', '+6281299990000')->sole();
    expect($budi->tenant_id)->toBe($this->pos->tenantId)->and($budi->consent_at)->not->toBeNull();
    $this->assertAuthenticatedAs($budi, MemberPortal::GUARD);
});

it('kata sandi sementara dari kasir/owner: wajib diganti dulu sebelum halaman lain', function () {
    $temporary = TenantContext::run($this->pos->tenantId, fn () => app(ChangeMemberPassword::class)->temporary($this->member));

    memberLogin($this, password: $temporary);
    $this->get('/m/kopi-senja')->assertRedirect('/m/kopi-senja/password');
    $this->get('/m/kopi-senja/history')->assertRedirect('/m/kopi-senja/password');

    $this->post('/m/kopi-senja/password', ['current_password' => $temporary, 'password' => 'baru-12345', 'password_confirmation' => 'baru-12345'])
        ->assertRedirect('/m/kopi-senja');
    $this->get('/m/kopi-senja')->assertOk();
});

it('ganti kata sandi: sesi lain keluar', function () {
    memberLogin($this);
    $session = $this->app['session']->all();

    TenantContext::run($this->pos->tenantId, fn () => app(ChangeMemberPassword::class)->handle($this->member, 'kata-baru-99'));

    $this->withSession($session)->get('/m/kopi-senja')->assertRedirect('/m/kopi-senja/login');
});

it('profil & riwayat; nomor HP tidak bisa diganti sendiri', function () {
    TenantContext::run($this->pos->tenantId, fn () => app(AdjustMemberPoints::class)->handle($this->member, 25, 'Bonus', User::factory()->owner()->create()));
    memberLogin($this);

    $this->get('/m/kopi-senja/history')->assertOk()->assertSee('Penyesuaian')->assertSee('+25');
    $this->post('/m/kopi-senja/profile', ['name' => 'Rina K', 'email' => 'rina@contoh.test', 'birth_day' => 17, 'birth_month' => 8, 'phone' => '0899'])
        ->assertSessionHasNoErrors();

    $member = Member::allTenants()->findOrFail($this->member->id);
    expect($member->name)->toBe('Rina K')->and($member->birth_month)->toBe(8)->and($member->phone)->toBe('+6281234567890');
});

it('hapus akun: wajib kata sandi, data dianonimkan, keluar', function () {
    memberLogin($this);
    $this->post('/m/kopi-senja/delete', ['password' => 'salah'])->assertSessionHasErrors('password');
    $this->post('/m/kopi-senja/delete', ['password' => 'rahasia-123'])->assertRedirect('/m/kopi-senja/login');

    $this->assertGuest(MemberPortal::GUARD);
    expect(Member::allTenants()->findOrFail($this->member->id)->anonymized_at)->not->toBeNull();
    memberLogin($this)->assertSessionHasErrors('phone');
});

it('endpoint kode: kode berlaku untuk menukar poin & cocok dengan MemberProof', function () {
    memberLogin($this);

    $data = $this->getJson('/m/kopi-senja/code')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    expect($data['payload'])->toBe($this->member->code.'-'.$data['code'])
        ->and($data['code'])->toBe(MemberProof::current($this->member));
});

it('kasir web: scan QR halaman member memasang member & mengisi bukti tukar poin', function () {
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create();
    app(AdjustMemberPoints::class)->handle($this->member, 300, 'Saldo', User::factory()->owner()->create());
    $points = PaymentMethod::query()->where('category', PaymentCategory::Points)->sole();

    Livewire::test(PointOfSale::class)
        ->set('memberSearch', $this->member->code.'-'.MemberProof::current($this->member))
        ->call('pickMember')
        ->assertSet('memberId', $this->member->id)
        ->call('addProduct', $this->pos->croissant->id)
        ->call('openPayment')
        ->call('setMethod', 0, $points->id)->set('payments.0.amount', '10000')
        ->call('addPaymentRow')->set('payments.1.tendered', '20000')
        ->call('pay');

    expect(Order::query()->sole()->points_redeemed)->toBe(100);
});
