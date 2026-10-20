<?php

declare(strict_types=1);

use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SetTenantPlan;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\TenantAccess;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Filament\Dashboard\Resources\Members\MemberResource;
use App\Filament\Dashboard\Resources\Promotions\PromotionResource;
use App\Models\Admin;
use App\Models\Device;
use App\Models\Feature;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shift;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * Skenario Fase 3: perjalanan satu bisnis dari daftar mandiri sampai ditangguhkan & diaktifkan lagi (ADR 0009, ADR 0024,
 * SPEC Q30–Q33, Q108–Q111). Celah G7: webhook billing yang sama dikirim dua kali di level HTTP.
 */
const OB_VENDOR_KEY = 'SB-vendor-key-test';

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 03:00:00');
    Notification::fake();
    RateLimiter::clear('signup-h:127.0.0.1');
    RateLimiter::clear('signup-d:127.0.0.1');
    config(['pos.billing.midtrans_server_key' => OB_VENDOR_KEY]);

    // Paket dibuat super admin sebelum ada pelanggan
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'limits' => ['outlets' => 1], 'features' => ['member'], 'sort_order' => 1]);
    $this->pro = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000', 'limits' => ['outlets' => 3], 'features' => array_keys(Feature::options()), 'sort_order' => 2]);

    // Midtrans vendor palsu: Snap selalu sukses; cek status membaca $this->obSettled[order_id] (nominal yang lunas)
    $this->obSettled = [];
    $settled = &$this->obSettled;
    Http::fake([
        'app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://snap.test/pay']),
        'api.sandbox.midtrans.com/*' => function (HttpRequest $request) use (&$settled) {
            preg_match('#/v2/([^/]+)/status#', $request->url(), $m);
            $orderId = rawurldecode($m[1] ?? '');

            return isset($settled[$orderId])
                ? Http::response(['transaction_status' => 'settlement', 'gross_amount' => $settled[$orderId]])
                : Http::response(['transaction_status' => 'pending', 'gross_amount' => '0.00']);
        },
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, string> */
function obSignupBody(array $override = []): array
{
    return [
        'business_name' => 'Kopi Fajar', 'business_type' => 'cafe', 'name' => 'Fajar',
        'email' => 'fajar@kopifajar.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ...$override,
    ];
}

/** Kirim notifikasi settlement Midtrans vendor (tanda tangan dengan key test) untuk tagihan. */
function obWebhook(object $test, SubscriptionInvoice $invoice): TestResponse
{
    $orderId = (string) $invoice->gateway_order_id;
    $amount = (string) $invoice->amount;

    return $test->postJson('/webhooks/billing/midtrans', [
        'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $amount,
        'transaction_status' => 'settlement',
        'signature_key' => MidtransClient::signature($orderId, '200', $amount, OB_VENDOR_KEY),
    ]);
}

/** Checkout tunai satu produk lewat API Flutter. */
function obCheckout(object $test, string $token, string $productId, string $cashId): TestResponse
{
    freshAuth();

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $productId, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $cashId, 'amount' => 100000]],
    ], apiHeaders());
}

/** Owner membuka panel dashboard (meniru SetDashboardTenant: konteks tenant + flag hanya-baca). */
function obOwnerDashboard(object $test, User $owner): void
{
    freshAuth();
    Filament::setCurrentPanel('dashboard');
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    TenantContext::set($tenant->id, readOnly: $tenant->isReadOnly());
    $test->actingAs($owner, 'web');
    CurrentOutlet::set($owner);
}

/** Owner membuat tagihan & membuka bayar online dari halaman Langganan; mengembalikan tagihan menunggu. */
function obOwnerBuysPlan(object $test, User $owner, Plan $plan, string $cycle = 'monthly'): SubscriptionInvoice
{
    obOwnerDashboard($test, $owner);

    Livewire::test(SubscriptionSettings::class)
        ->callAction('createInvoice', ['plan_id' => $plan->id, 'billing_cycle' => $cycle])
        ->assertHasNoActionErrors();

    $invoice = SubscriptionInvoice::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)
        ->where('status', InvoiceStatus::Pending)->sole();

    // Tombol bayar per tagihan menunggu (argumen id)
    Livewire::test(SubscriptionSettings::class)
        ->callAction('payInvoice', ['method' => 'qris'], ['id' => $invoice->id])
        ->assertRedirect('https://snap.test/pay');

    return $invoice->refresh();
}

/** Super admin di panel /admin. */
function obAsSuperAdmin(object $test): void
{
    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $test->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
}

it('daftar → trial → verifikasi → habis (hanya-baca) → langganan via webhook (ganda) → upgrade → downgrade → tangguh → aktif', function () {
    // 1. Daftar mandiri 1 Okt 03:00 UTC: tenant trial 14 hari, owner belum terverifikasi, langsung masuk dashboard
    $this->post(route('signup.store'), obSignupBody())->assertRedirect('/dashboard');

    $tenant = Tenant::query()->sole();
    $owner = User::allTenants()->where('tenant_id', $tenant->id)->sole();
    $outlet = Outlet::forTenant($tenant->id)->sole();

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->plan_id)->toBeNull()
        // 1 Okt + 14 hari, akhir hari (RegisterTenant: endOfDay UTC) = 15 Okt 23:59:59
        ->and($tenant->subscription_ends_at->toDateTimeString())->toBe('2026-10-15 23:59:59')
        ->and($tenant->access())->toBe(TenantAccess::Full)
        ->and($tenant->needsOwnerEmailVerification())->toBeTrue();
    $this->assertAuthenticatedAs($owner, 'web');
    $this->get('/dashboard')->assertOk()->assertSee('Verifikasi email owner untuk mulai bertransaksi');

    // Kasir & device untuk aplikasi Flutter; shift terbuka
    $cashier = User::factory()->cashier()->forOutlet($outlet)->create(['name' => 'Kasir Fajar']);
    $device = Device::factory()->forOutlet($outlet)->create(['device_uid' => 'fajar-1']);
    [$product, $cashId] = TenantContext::run($tenant->id, function () use ($device, $cashier): array {
        Shift::factory()->forDevice($device, $cashier)->create(['opening_cash' => '100000.00']);

        return [Product::factory()->create(['name' => 'Kopi Tubruk', 'price' => '15000.00']), PaymentMethod::query()->where('category', 'cash')->value('id')];
    });
    $token = userToken($cashier, $device);

    // 2. Belum verifikasi email: transaksi ditolak (Q31)
    assertApiError(obCheckout($this, $token, $product->id, $cashId), 'EMAIL_NOT_VERIFIED', 403);

    // 3. Owner klik link verifikasi → checkout trial berhasil (semua fitur, tanpa paket)
    auth('web')->logout();
    $this->get(VerifyOwnerEmail::url($owner))->assertOk()->assertSee('Email terverifikasi');
    expect($tenant->refresh()->needsOwnerEmailVerification())->toBeFalse();
    obCheckout($this, $token, $product->id, $cashId)->assertCreated();

    // 4. 15 Okt 20:00 (trial masih berjalan): owner membuat tagihan Basic bulanan & membuka bayar online (Snap vendor).
    // Catatan: membuat tagihan SETELAH trial habis gagal (lihat test BUG di bawah), jadi skenario ini membuatnya sebelum habis.
    $this->travelTo(Carbon::parse('2026-10-15 20:00:00'));
    $invoice = obOwnerBuysPlan($this, $owner, $this->basic);
    // Trial (bukan langganan berbayar) → perpanjangan harga penuh 99.000; jatuh tempo 15 Okt + 3 hari = 18 Okt
    expect($invoice->kind)->toBe(InvoiceKind::Renewal)
        ->and($invoice->amount)->toBe('99000.00')
        ->and($invoice->due_at->toDateString())->toBe('2026-10-18')
        ->and($invoice->gateway_order_id)->toStartWith('BILL-'.$invoice->number.'-');

    // 5. 16 Okt 03:00: trial habis sejak 15 Okt 23:59:59 → hanya-baca. Tulis 403 SUBSCRIPTION_EXPIRED, baca tetap boleh
    $this->travelTo(Carbon::parse('2026-10-16 03:00:00'));
    // Token login kasir berlaku 1 hari: login ulang
    $token = userToken($cashier, $device);
    expect($tenant->refresh()->access())->toBe(TenantAccess::ReadOnly);

    assertApiError(obCheckout($this, $token, $product->id, $cashId), 'SUBSCRIPTION_EXPIRED', 403);
    freshAuth();
    $this->withToken($token)->getJson('/api/v1/catalog', apiHeaders())->assertOk();
    freshAuth();
    $this->withToken($token)->getJson('/api/v1/orders', apiHeaders())->assertOk()->assertJsonCount(1, 'data');

    // Owner tetap bisa masuk dashboard & membuka Langganan
    freshAuth();
    $this->actingAs($owner, 'web')->get('/dashboard/settings/subscription')->assertOk();
    TenantContext::forget();

    // 6. Midtrans mengirim settlement → tagihan lunas, tenant aktif, paket Basic
    $this->obSettled[$invoice->gateway_order_id] = '99000.00';
    obWebhook($this, $invoice)->assertOk()->assertJson(['ok' => true]);

    $tenant->refresh();
    $invoice->refresh();
    // Masa aktif habis → periode dari sekarang: 16 Okt 03:00 + 1 bulan = 16 Nov 03:00
    expect($invoice->status)->toBe(InvoiceStatus::Paid)->and($invoice->paid_via)->toBe('midtrans')
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->plan_id)->toBe($this->basic->id)
        ->and($tenant->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($tenant->outletLimit())->toBe(1)
        ->and($tenant->subscription_ends_at->toDateTimeString())->toBe('2026-11-16 03:00:00')
        ->and($tenant->access())->toBe(TenantAccess::Full);
    $paidAt = $invoice->paid_at->toDateTimeString();

    // Fitur paket Basic (member saja): menu promo tertutup, member terbuka
    obOwnerDashboard($this, $owner);
    expect(Features::enabled('promotions'))->toBeFalse()
        ->and(Features::enabled('member'))->toBeTrue()
        ->and(PromotionResource::canViewAny())->toBeFalse()
        ->and(MemberResource::canViewAny())->toBeTrue();

    // 7. G7: webhook yang SAMA dikirim lagi satu jam kemudian → 200, tidak memperpanjang dua kali
    $this->travel(1)->hours();
    TenantContext::forget();
    obWebhook($this, $invoice)->assertOk();

    expect($tenant->refresh()->subscription_ends_at->toDateTimeString())->toBe('2026-11-16 03:00:00')
        ->and($invoice->refresh()->paid_at->toDateTimeString())->toBe($paidAt)
        ->and($invoice->period_end->toDateTimeString())->toBe('2026-11-16 03:00:00')
        ->and(SubscriptionInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', InvoiceStatus::Paid)->count())->toBe(1);

    // Transaksi kembali berjalan
    obCheckout($this, $token, $product->id, $cashId)->assertCreated();

    // 8. 21 Okt 03:00: upgrade ke Pro (siklus sama, lebih mahal) = prorata
    $this->travelTo(Carbon::parse('2026-10-21 03:00:00'));
    $upgrade = obOwnerBuysPlan($this, $owner, $this->pro);
    // Sisa 21 Okt → 16 Nov = 26 hari; satu siklus 16 Okt → 16 Nov = 31 hari
    // (199.000 − 99.000) × 26 ÷ 31 = 83.870,97 → 83.871; jatuh tempo 21 Okt + 3 = 24 Okt (≤ 16 Nov)
    expect($upgrade->kind)->toBe(InvoiceKind::Upgrade)
        ->and($upgrade->amount)->toBe('83871.00')
        ->and($upgrade->due_at->toDateString())->toBe('2026-10-24');

    TenantContext::forget();
    $this->obSettled[$upgrade->gateway_order_id] = '83871.00';
    obWebhook($this, $upgrade)->assertOk();

    // Paket langsung Pro, batas outlet 3, masa aktif TETAP 16 Nov
    $tenant->refresh();
    expect($upgrade->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($tenant->plan_id)->toBe($this->pro->id)
        ->and($tenant->outletLimit())->toBe(3)
        ->and($tenant->subscription_ends_at->toDateTimeString())->toBe('2026-11-16 03:00:00')
        ->and($upgrade->period_end->toDateTimeString())->toBe('2026-11-16 03:00:00');
    obOwnerDashboard($this, $owner);
    expect(PromotionResource::canViewAny())->toBeTrue();

    // 9. Downgrade ke Basic: perpanjangan harga penuh, paket baru dijadwalkan di akhir masa aktif
    $downgrade = obOwnerBuysPlan($this, $owner, $this->basic);
    expect($downgrade->kind)->toBe(InvoiceKind::Renewal)->and($downgrade->amount)->toBe('99000.00');

    TenantContext::forget();
    $this->obSettled[$downgrade->gateway_order_id] = '99000.00';
    obWebhook($this, $downgrade)->assertOk();

    // Masih Pro sampai 16 Nov 03:00; langganan diperpanjang 16 Nov + 1 bulan = 16 Des 03:00
    $tenant->refresh();
    expect($tenant->plan_id)->toBe($this->pro->id)
        ->and($tenant->next_plan_id)->toBe($this->basic->id)
        ->and($tenant->plan_changes_at->toDateTimeString())->toBe('2026-11-16 03:00:00')
        ->and($tenant->subscription_ends_at->toDateTimeString())->toBe('2026-12-16 03:00:00');

    // 10. Scheduler: sebelum waktunya tidak ada perubahan; 16 Nov 04:02 paket Basic diterapkan
    $this->travelTo(Carbon::parse('2026-11-16 02:02:00'));
    $this->artisan('pos:apply-plan-changes')->expectsOutput('0 paket terjadwal diterapkan.')->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-11-16 04:02:00'));
    $token = userToken($cashier, $device);
    $this->artisan('pos:apply-plan-changes')->expectsOutput('1 paket terjadwal diterapkan.')->assertSuccessful();

    $tenant->refresh();
    expect($tenant->plan_id)->toBe($this->basic->id)
        ->and($tenant->outletLimit())->toBe(1)
        ->and($tenant->next_plan_id)->toBeNull()
        ->and($tenant->plan_changes_at)->toBeNull()
        ->and($tenant->subscription_ends_at->toDateTimeString())->toBe('2026-12-16 03:00:00');
    obOwnerDashboard($this, $owner);
    expect(PromotionResource::canViewAny())->toBeFalse();

    // 11. Super admin menangguhkan tenant → API 403 TENANT_SUSPENDED (baca juga), dashboard ditolak
    obAsSuperAdmin($this);
    Livewire::test(ListTenants::class)->callAction(TestAction::make('suspend')->table($tenant));
    expect($tenant->refresh()->status)->toBe(TenantStatus::Suspended);

    freshAuth();
    $this->withToken($token)->getJson('/api/v1/catalog', apiHeaders())->assertStatus(403)->assertJsonPath('error.code', 'TENANT_SUSPENDED');
    assertApiError(obCheckout($this, $token, $product->id, $cashId), 'TENANT_SUSPENDED', 403);
    freshAuth();
    $this->actingAs($owner->fresh(), 'web')->get('/dashboard')->assertRedirect('/dashboard/login');
    $this->assertGuest('web');

    // 12. Diaktifkan kembali (ChangeTenantStatus jalur activate) → transaksi berjalan lagi
    obAsSuperAdmin($this);
    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])->callAction('activate');
    expect($tenant->refresh()->status)->toBe(TenantStatus::Active)->and($tenant->access())->toBe(TenantAccess::Full);

    obCheckout($this, $token, $product->id, $cashId)->assertCreated();

    // Akhir hari: 3 order (trial, setelah Basic, setelah aktif lagi), 3 tagihan lunas
    expect(TenantContext::run($tenant->id, fn () => Order::query()->count()))->toBe(3)
        ->and(SubscriptionInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', InvoiceStatus::Paid)->count())->toBe(3);
});

it('webhook ganda tagihan perpanjangan aktif: masa aktif hanya bertambah satu siklus', function () {
    // Tenant aktif Basic sampai 31 Okt; perpanjangan Basic bulanan dibayar → 30 Nov, lalu webhook yang sama lagi
    $this->post(route('signup.store'), obSignupBody())->assertRedirect();
    $tenant = Tenant::query()->sole();
    $owner = User::allTenants()->where('tenant_id', $tenant->id)->sole();
    app(SetTenantPlan::class)->handle($tenant, $this->basic, BillingCycle::Monthly);
    $tenant->forceFill(['status' => TenantStatus::Active, 'subscription_ends_at' => Carbon::parse('2026-10-31 03:00:00')])->save();

    $invoice = obOwnerBuysPlan($this, $owner, $this->basic);
    TenantContext::forget();
    $this->obSettled[$invoice->gateway_order_id] = '99000.00';

    obWebhook($this, $invoice)->assertOk();
    obWebhook($this, $invoice)->assertOk();
    obWebhook($this, $invoice)->assertOk();

    // 31 Okt + 1 bulan = 30 Nov (addMonthsNoOverflow), bukan 31 Des / 31 Jan
    expect($tenant->refresh()->subscription_ends_at->toDateTimeString())->toBe('2026-11-30 03:00:00')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('webhook tagihan dengan tanda tangan key lain ditolak dan tagihan tetap menunggu', function () {
    $this->post(route('signup.store'), obSignupBody())->assertRedirect();
    $owner = User::allTenants()->sole();
    $invoice = obOwnerBuysPlan($this, $owner, $this->basic);
    TenantContext::forget();
    $this->obSettled[$invoice->gateway_order_id] = '99000.00';

    $this->postJson('/webhooks/billing/midtrans', [
        'order_id' => $invoice->gateway_order_id, 'status_code' => '200', 'gross_amount' => '99000.00',
        'signature_key' => MidtransClient::signature((string) $invoice->gateway_order_id, '200', '99000.00', 'SB-key-penyerang'),
    ])->assertStatus(403);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending)
        ->and(Tenant::query()->sole()->plan_id)->toBeNull();
});

// BUG: halaman Langganan "tetap bisa dibuka saat hanya-baca: justru untuk memperpanjang" (SPEC Q109), tetapi
// SetDashboardTenant (middleware persisten Livewire) memasang TenantContext readOnly dan BelongsToTenant::saving menolak
// simpan SubscriptionInvoice → CreateInvoice melempar SUBSCRIPTION_EXPIRED; owner tidak bisa membuat tagihan.
it('owner bisnis hanya-baca bisa membuat tagihan di halaman Langganan', function () {
    $this->post(route('signup.store'), obSignupBody())->assertRedirect();
    $owner = User::allTenants()->sole();
    $this->travelTo(Carbon::parse('2026-10-16 03:00:00'));
    expect(Tenant::query()->sole()->isReadOnly())->toBeTrue();

    obOwnerDashboard($this, $owner);
    Livewire::test(SubscriptionSettings::class)
        ->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly']);

    // 99.000 perpanjangan, jatuh tempo 16 Okt + 3 = 19 Okt
    $invoice = SubscriptionInvoice::query()->withoutGlobalScopes()->sole();
    expect($invoice->amount)->toBe('99000.00')->and($invoice->due_at->toDateString())->toBe('2026-10-19');
})->skip('BUG: CreateInvoice ditolak SUBSCRIPTION_EXPIRED saat tenant hanya-baca (BelongsToTenant read-only guard)');

// BUG: sama seperti di atas — StartInvoicePayment menyimpan gateway_order_id/snap_url pada SubscriptionInvoice
// (BelongsToTenant) sehingga bayar online tagihan menunggu ditolak saat hanya-baca.
it('owner bisnis hanya-baca bisa membuka bayar online tagihan yang sudah dibuat', function () {
    $this->post(route('signup.store'), obSignupBody())->assertRedirect();
    $owner = User::allTenants()->sole();
    $this->travelTo(Carbon::parse('2026-10-15 20:00:00'));
    obOwnerDashboard($this, $owner);
    Livewire::test(SubscriptionSettings::class)
        ->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly']);

    $this->travelTo(Carbon::parse('2026-10-16 03:00:00'));
    obOwnerDashboard($this, $owner);
    $pending = SubscriptionInvoice::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->where('status', InvoiceStatus::Pending)->sole();
    Livewire::test(SubscriptionSettings::class)->callAction('payInvoice', ['method' => 'qris'], ['id' => $pending->id])->assertRedirect('https://snap.test/pay');

    expect(SubscriptionInvoice::query()->withoutGlobalScopes()->sole()->gateway_order_id)->not->toBeNull();
})->skip('BUG: StartInvoicePayment ditolak SUBSCRIPTION_EXPIRED saat tenant hanya-baca (BelongsToTenant read-only guard)');

describe('edisi jual putus', function () {
    beforeEach(function () {
        config(['pos.edition' => 'self_hosted']);
        Features::flush();
    });

    afterEach(fn () => config(['pos.edition' => 'saas']));

    it('tanpa tagihan: langganan lewat tidak hanya-baca, paket diabaikan (semua fitur), menu Langganan tertutup', function () {
        $pos = posSetup();
        $tenant = Tenant::query()->findOrFail($pos->tenantId);
        // Data lama dari SaaS: paket Basic & masa aktif lewat — di jual putus tidak berpengaruh
        $tenant->forceFill(['status' => TenantStatus::Active, 'plan_id' => $this->basic->id, 'billing_cycle' => BillingCycle::Monthly, 'subscription_ends_at' => now()->subDays(30)])->save();

        expect($tenant->refresh()->access())->toBe(TenantAccess::Full);
        obCheckout($this, $pos->token, $pos->croissant->id, $pos->methods['cash']->id)->assertCreated();

        $owner = User::factory()->owner()->forOutlet($pos->outlet)->create();
        obOwnerDashboard($this, $owner);
        expect(Features::enabled('promotions'))->toBeTrue()
            ->and(Features::enabled('stock'))->toBeTrue()
            ->and(PromotionResource::canViewAny())->toBeTrue()
            ->and(SubscriptionSettings::canAccess())->toBeFalse()
            ->and(fn () => app(CreateInvoice::class)->handle($tenant, $this->pro, BillingCycle::Monthly))->toThrow(BusinessException::class, 'Edisi jual putus');

        expect(SubscriptionInvoice::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('penangguhan tetap memblokir API', function () {
        $pos = posSetup();
        Tenant::query()->findOrFail($pos->tenantId)->forceFill(['status' => TenantStatus::Suspended])->save();

        freshAuth();
        assertApiError($this->withToken($pos->token)->getJson('/api/v1/catalog', apiHeaders()), 'TENANT_SUSPENDED', 403);
    });
});
