<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Actions\SelfOrder\CheckoutSelfOrder;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\Member;
use App\Models\SelfOrder;
use App\Models\Tenant;
use App\Support\MemberPortal;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Login/daftar member wajib sebelum membayar & keranjang selamat (ADR 0027 K1, K5; SPEC Q137 T3). */
beforeEach(function () {
    $this->pos = posSetup();
    Tenant::query()->whereKey($this->pos->tenantId)->update(['slug' => 'kopi-senja']);
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 3']);
    $this->member = Member::factory()->create(['name' => 'Rina', 'phone' => '+6281234567890']);
    $this->member->forceFill(['password' => 'rahasia-123'])->save();
    $this->token = $this->table->qr_token;
    RateLimiter::clear('self-order-ip:127.0.0.1');
});

function loginPage(object $test): Testable
{
    SelfOrderContext::set(SelfOrderContext::resolve($test->table->qr_token));

    return Livewire::test(OrderPage::class);
}

function cartInSession(object $test): array
{
    return (array) session()->get(OrderPage::cartKey($test->table->id), []);
}

it('tamu boleh memilih menu, tetapi wajib masuk sebelum mengirim (Livewire & Action)', function () {
    $page = loginPage($this)
        ->call('quickAdd', $this->pos->croissant->id)
        ->call('show', 'cart')
        ->assertSee('Masuk untuk bayar')
        ->assertSee('/order/t/'.$this->token.'/login?from=cart', false)
        ->assertDontSee('Nama pemesan')
        ->call('openPay')
        ->assertSet('payOpen', false)
        ->assertSet('error', 'Masuk sebagai member dulu untuk membayar. Keranjangmu tetap tersimpan.');

    expect(SelfOrder::query()->count())->toBe(0)
        ->and(cartInSession($this)['cart'][0]['product_id'])->toBe($this->pos->croissant->id);

    // Lapisan Action juga menolak (tidak bergantung pada tampilan): checkout baru & kirim lama
    foreach ([CheckoutSelfOrder::class, SubmitSelfOrder::class] as $action) {
        expect(fn () => app($action)->handle(SelfOrderContext::resolve($this->token), [
            'id' => (string) Str::uuid7(), 'customer_name' => 'Tamu', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        ], null, '127.0.0.1'))->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::Unauthenticated->value));
    }
});

it('program member nonaktif: login tidak mungkin, tamu tetap memesan dengan nama; halaman masuk meja 404', function () {
    app(UpdateMemberProgram::class)->handle(false, []);

    loginPage($this)->call('quickAdd', $this->pos->croissant->id)->call('show', 'cart')
        ->assertSee('Nama pemesan')->assertDontSee('Masuk untuk bayar')
        ->set('customerName', 'Budi')->call('openPay')->call('choosePay', 'cashier')->assertSet('payError', null)->assertSet('view', 'pay');

    expect(SelfOrder::query()->sole()->customer_name)->toBe('Budi');
    $this->get('/order/t/'.$this->token.'/login')->assertNotFound();
});

it('masuk dari keranjang: kembali ke meja, Keranjang terbuka & isinya utuh, pesanan atas nama member', function () {
    loginPage($this)->call('quickAdd', $this->pos->croissant->id)->call('quickAdd', $this->pos->croissant->id)->set('notes', 'tanpa sedotan');

    $this->get('/order/t/'.$this->token.'/login?from=cart')->assertOk()
        ->assertSee('Masuk member')->assertSee('so-input-icon', false)->assertSee('data-so-reveal', false);

    $this->post('/order/t/'.$this->token.'/login', ['phone' => '0812-3456-7890', 'password' => 'rahasia-123'])
        ->assertRedirect($this->table->orderUrl());
    expect(MemberPortal::member()?->id)->toBe($this->member->id);

    loginPage($this)
        ->assertSet('view', 'cart')
        ->assertSet('cart.0.qty', 2)
        ->assertSet('notes', 'tanpa sedotan')
        ->assertSee('Poin & diskon member ikut dihitung', false)
        ->call('openPay')->call('choosePay', 'cashier')->assertSet('payError', null)->assertSet('view', 'pay');

    $order = SelfOrder::query()->sole();
    expect($order->member_id)->toBe($this->member->id)->and($order->customer_name)->toBe('Rina')
        ->and($order->notes)->toBe('tanpa sedotan')
        ->and(cartInSession($this)['cart'])->toBe([]);
});

it('masuk: kata sandi salah = pesan umum, dibatasi 5/menit per HP+IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/order/t/'.$this->token.'/login', ['phone' => '081234567890', 'password' => 'salah-'.$i])
            ->assertSessionHasErrors(['phone' => 'Nomor HP atau kata sandi salah']);
    }

    $this->post('/order/t/'.$this->token.'/login', ['phone' => '081234567890', 'password' => 'rahasia-123'])
        ->assertSessionHasErrors('phone');
    expect(MemberPortal::member())->toBeNull();
});

it('daftar singkat dari meja: tanpa ulangi kata sandi, persetujuan wajib, langsung masuk & kembali ke meja', function () {
    $this->post('/order/t/'.$this->token.'/register', ['name' => 'Sari', 'phone' => '081311112222', 'password' => 'lemah'])
        ->assertSessionHasErrors(['password', 'consent']);

    $this->post('/order/t/'.$this->token.'/register', ['name' => 'Sari', 'phone' => '081311112222', 'password' => 'kopisenja1', 'consent' => '1'])
        ->assertRedirect($this->table->orderUrl());

    $member = Member::query()->where('phone', '+6281311112222')->sole();
    expect($member->name)->toBe('Sari')->and(MemberPortal::member()?->id)->toBe($member->id);

    // Nomor yang sudah terdaftar tidak bisa diklaim ulang
    MemberPortal::guard()->logout();
    $this->post('/order/t/'.$this->token.'/register', ['name' => 'Palsu', 'phone' => '081234567890', 'password' => 'kopisenja1', 'consent' => '1'])
        ->assertSessionHasErrors('phone');
});

it('kata sandi sementara dari kasir: ganti dulu di halaman member lalu kembali ke meja', function () {
    $this->member->forceFill(['must_change_password' => true])->save();

    $this->post('/order/t/'.$this->token.'/login', ['phone' => '081234567890', 'password' => 'rahasia-123'])
        ->assertRedirect('/m/kopi-senja/password');

    $this->post('/m/kopi-senja/password', ['current_password' => 'rahasia-123', 'password' => 'baru-rahasia9', 'password_confirmation' => 'baru-rahasia9'])
        ->assertRedirect($this->table->orderUrl());
});

it('keluar dari meja: sesi member putus, keranjang tetap', function () {
    loginPage($this)->call('quickAdd', $this->pos->croissant->id);
    $this->post('/order/t/'.$this->token.'/login', ['phone' => '081234567890', 'password' => 'rahasia-123']);

    $this->post('/order/t/'.$this->token.'/logout')->assertRedirect($this->table->orderUrl());
    expect(MemberPortal::member())->toBeNull()->and(cartInSession($this)['cart'])->toHaveCount(1);
});

it('keranjang dipulihkan per meja & divalidasi ulang: meja lain kosong, produk asing/tenant lain dibuang', function () {
    $other = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);
    $otherTable = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 4']);

    session()->put(OrderPage::cartKey($this->table->id), ['cart' => [
        ['product_id' => $this->pos->croissant->id, 'name' => 'Diganti', 'qty' => 80, 'option_ids' => [], 'option_names' => ['Palsu'], 'notes' => null],
        ['product_id' => $other->croissant->id, 'name' => 'Tenant lain', 'qty' => 1, 'option_ids' => [], 'option_names' => [], 'notes' => null],
        ['product_id' => $this->pos->coffee->id, 'name' => 'Kopi', 'qty' => 1, 'option_ids' => [$this->pos->large->id, $other->large->id], 'option_names' => [], 'notes' => null],
    ], 'notes' => 'hai', 'voucher' => '']);

    $cart = loginPage($this)->get('cart');
    expect($cart)->toHaveCount(1)
        ->and($cart[0]['name'])->toBe('Croissant')
        ->and($cart[0]['qty'])->toBe(SubmitSelfOrder::MAX_ITEMS)
        ->and($cart[0]['option_names'])->toBe([]);

    SelfOrderContext::set(SelfOrderContext::resolve($otherTable->qr_token));
    expect(Livewire::test(OrderPage::class)->get('cart'))->toBe([]);
});
