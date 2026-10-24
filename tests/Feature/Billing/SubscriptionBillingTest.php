<?php

declare(strict_types=1);

use App\Actions\Billing\ApplyScheduledPlanChanges;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SendBillingReminders;
use App\Actions\Billing\SetTenantPlan;
use App\Actions\Billing\StartInvoicePayment;
use App\Actions\Promotion\SavePromotion;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Filament\Dashboard\Resources\Promotions\PromotionResource;
use App\Models\Admin;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionEndingSoon;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** Paket langganan & tagihan SaaS (ADR 0024, Q108–Q111). */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 03:00:00');
    $this->pos = posSetup();
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->tenant->forceFill(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)])->save();
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'limits' => ['outlets' => 1], 'features' => ['member']]);
    $this->pro = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000', 'limits' => ['outlets' => 3], 'features' => array_keys(Feature::options())]);
});

afterEach(fn () => Carbon::setTestNow());

function invoiceFor(object $test, ?Plan $plan = null, BillingCycle $cycle = BillingCycle::Monthly): SubscriptionInvoice
{
    return TenantContext::run($test->tenant->id, fn () => app(CreateInvoice::class)->handle($test->tenant->refresh(), $plan ?? $test->pro, $cycle));
}

it('buat tagihan: nominal & nama snapshot, jatuh tempo 3 hari, tagihan menunggu lama dibatalkan', function () {
    $first = invoiceFor($this);
    $second = invoiceFor($this, $this->basic, BillingCycle::Yearly);

    expect($second->amount)->toBe('990000.00')->and($second->plan_name)->toBe('Basic')
        ->and($second->due_at->toDateString())->toBe('2026-10-19')
        ->and($first->refresh()->status)->toBe(InvoiceStatus::Cancelled);

    $hidden = app(SavePlan::class)->handle(null, ['code' => 'khusus', 'name' => 'Khusus', 'price_monthly' => '1', 'price_yearly' => '1', 'is_public' => false]);
    expect(fn () => invoiceFor($this, $hidden))->toThrow(BusinessException::class, 'Paket tidak tersedia');

    config(['pos.edition' => 'self_hosted']);
    expect(fn () => invoiceFor($this))->toThrow(BusinessException::class);
});

it('lunas: paket, batas outlet, status aktif, masa aktif ditambah dari akhir lama; idempotent', function () {
    $invoice = invoiceFor($this);
    app(MarkInvoicePaid::class)->handle($invoice, 'manual');
    app(MarkInvoicePaid::class)->handle($invoice, 'manual');

    $tenant = $this->tenant->refresh();
    expect($tenant->plan_id)->toBe($this->pro->id)->and($tenant->outletLimit())->toBe(3)
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->subscription_ends_at->toDateString())->toBe('2026-11-21')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->period_end->toDateString())->toBe('2026-11-21');

    // Sudah habis: dihitung dari sekarang
    $tenant->forceFill(['subscription_ends_at' => now()->subDays(10)])->save();
    app(MarkInvoicePaid::class)->handle(invoiceFor($this, cycle: BillingCycle::Yearly), 'manual');
    expect($this->tenant->refresh()->subscription_ends_at->toDateString())->toBe('2027-10-16');
});

/** Langganan berbayar berjalan: paket $plan bulanan, berakhir 15 hari lagi (siklus 30 Sep–31 Okt = 31 hari). */
function activeOn(object $test, Plan $plan): void
{
    $test->tenant->forceFill([
        'status' => TenantStatus::Active, 'plan_id' => $plan->id, 'billing_cycle' => BillingCycle::Monthly,
        'limit_overrides' => null, 'subscription_ends_at' => now()->addDays(15),
    ])->save();
}

it('upgrade prorata: selisih × sisa hari ÷ hari siklus; lunas = paket langsung ganti, masa aktif tetap', function () {
    activeOn($this, $this->basic);
    $invoice = invoiceFor($this);

    // (199.000 − 99.000) × 15 ÷ 31 = 48.387,09 → 48.387
    expect($invoice->kind)->toBe(InvoiceKind::Upgrade)->and($invoice->amount)->toBe('48387.00')
        ->and($invoice->due_at->toDateString())->toBe('2026-10-19');

    app(MarkInvoicePaid::class)->handle($invoice, 'manual');
    $tenant = $this->tenant->refresh();
    expect($tenant->plan_id)->toBe($this->pro->id)->and($tenant->outletLimit())->toBe(3)
        ->and($tenant->subscription_ends_at->toDateString())->toBe('2026-10-31')
        ->and($tenant->next_plan_id)->toBeNull()
        ->and($invoice->refresh()->period_end->toDateString())->toBe('2026-10-31');

    // Jatuh tempo upgrade tidak melewati akhir masa aktif
    activeOn($this, $this->basic);
    $this->tenant->forceFill(['subscription_ends_at' => now()->addDay()])->save();
    expect(invoiceFor($this)->due_at->toDateString())->toBe('2026-10-17');
});

it('downgrade / ganti siklus: perpanjangan harga penuh, paket baru dijadwalkan di akhir masa aktif', function () {
    activeOn($this, $this->pro);
    $invoice = invoiceFor($this, $this->basic);
    expect($invoice->kind)->toBe(InvoiceKind::Renewal)->and($invoice->amount)->toBe('99000.00');

    app(MarkInvoicePaid::class)->handle($invoice, 'manual');
    $tenant = $this->tenant->refresh();
    expect($tenant->plan_id)->toBe($this->pro->id)
        ->and($tenant->next_plan_id)->toBe($this->basic->id)
        ->and($tenant->plan_changes_at->toDateString())->toBe('2026-10-31')
        ->and($tenant->subscription_ends_at->toDateString())->toBe('2026-11-30');

    expect(app(ApplyScheduledPlanChanges::class)->handle(CarbonImmutable::parse('2026-10-30 03:00')))->toBe(0)
        ->and(app(ApplyScheduledPlanChanges::class)->handle(CarbonImmutable::parse('2026-10-31 04:00')))->toBe(1)
        ->and(app(ApplyScheduledPlanChanges::class)->handle(CarbonImmutable::parse('2026-10-31 05:00')))->toBe(0);

    $tenant = $this->tenant->refresh();
    expect($tenant->plan_id)->toBe($this->basic->id)->and($tenant->outletLimit())->toBe(1)->and($tenant->next_plan_id)->toBeNull();

    // Paket lebih mahal tapi siklus lain = perpanjangan terjadwal, bukan upgrade
    activeOn($this, $this->basic);
    $yearly = invoiceFor($this, cycle: BillingCycle::Yearly);
    expect($yearly->kind)->toBe(InvoiceKind::Renewal)->and($yearly->amount)->toBe('1990000.00');
    app(MarkInvoicePaid::class)->handle($yearly, 'manual');
    expect($this->tenant->refresh()->next_billing_cycle)->toBe(BillingCycle::Yearly)->and($this->tenant->plan_id)->toBe($this->basic->id);

    // Admin mengatur paket langsung: jadwal dibatalkan
    app(SetTenantPlan::class)->handle($this->tenant, $this->pro, BillingCycle::Monthly);
    expect($this->tenant->refresh()->next_plan_id)->toBeNull();
});

it('trial: perpanjangan langsung mengganti paket (tanpa jadwal)', function () {
    $this->tenant->forceFill(['plan_id' => $this->basic->id, 'billing_cycle' => BillingCycle::Monthly])->save();
    $invoice = invoiceFor($this);
    expect($invoice->kind)->toBe(InvoiceKind::Renewal)->and($invoice->amount)->toBe('199000.00');

    app(MarkInvoicePaid::class)->handle($invoice, 'manual');
    expect($this->tenant->refresh()->plan_id)->toBe($this->pro->id)->and($this->tenant->next_plan_id)->toBeNull();
});

it('bayar online: Snap vendor sekali per tagihan; tanpa key = belum tersedia', function () {
    $invoice = invoiceFor($this);
    $owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create());

    expect(fn () => app(StartInvoicePayment::class)->handle($invoice, $owner, 'https://app.test/back'))->toThrow(BusinessException::class, 'transfer manual');

    config(['pos.billing.midtrans_server_key' => 'SB-vendor-key']);
    Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://snap.test/pay'])]);

    expect(app(StartInvoicePayment::class)->handle($invoice, $owner, 'https://app.test/back'))->toBe('https://snap.test/pay')
        ->and(app(StartInvoicePayment::class)->handle($invoice->refresh(), $owner, 'https://app.test/back'))->toBe('https://snap.test/pay');
    Http::assertSentCount(1);
});

it('webhook vendor: tanda tangan, cek status API, nominal; lunas memperpanjang langganan', function () {
    config(['pos.billing.midtrans_server_key' => 'SB-vendor-key']);
    $invoice = invoiceFor($this);
    $invoice->forceFill(['gateway_order_id' => 'BILL-X'])->save();
    $sign = fn (string $amount): string => MidtransClient::signature('BILL-X', '200', $amount, 'SB-vendor-key');

    $this->postJson('/webhooks/billing/midtrans', ['order_id' => 'BILL-X', 'status_code' => '200', 'gross_amount' => '199000.00', 'signature_key' => 'salah'])->assertStatus(403);
    Http::preventStrayRequests();
    $this->postJson('/webhooks/billing/midtrans', ['order_id' => 'TIDAK-ADA', 'status_code' => '200', 'gross_amount' => '1', 'signature_key' => MidtransClient::signature('TIDAK-ADA', '200', '1', 'SB-vendor-key')])->assertOk();
    // Order tidak dikenal (notifikasi uji Midtrans / situs lain): diterima tanpa cek status ke API (request liar = gagal)
    // & tanpa mengubah tagihan
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending);

    Http::fake(['api.sandbox.midtrans.com/v2/BILL-X/status' => Http::sequence()
        ->push(['transaction_status' => 'settlement', 'gross_amount' => '1000.00'])
        ->push(['transaction_status' => 'settlement', 'gross_amount' => '199000.00'])]);

    $this->postJson('/webhooks/billing/midtrans', ['order_id' => 'BILL-X', 'status_code' => '200', 'gross_amount' => '199000.00', 'signature_key' => $sign('199000.00')])->assertStatus(422);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending);

    $this->postJson('/webhooks/billing/midtrans', ['order_id' => 'BILL-X', 'status_code' => '200', 'gross_amount' => '199000.00', 'signature_key' => $sign('199000.00')])->assertOk();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)->and($invoice->paid_via)->toBe('midtrans')
        ->and($this->tenant->refresh()->plan_id)->toBe($this->pro->id);
});

it('fitur paket: Basic mematikan promo (mesin & menu); tanpa paket / jual putus = semua fitur', function () {
    TenantContext::set($this->tenant->id);
    app(SavePromotion::class)->handle(null, ['name' => 'Diskon', 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '50']);
    expect(Features::enabled('promotions'))->toBeTrue();

    app(SetTenantPlan::class)->handle($this->tenant, $this->basic, BillingCycle::Monthly);
    expect(Features::enabled('promotions'))->toBeFalse()->and(Features::enabled('member'))->toBeTrue();

    freshAuth();
    $order = $this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]],
    ], apiHeaders())->assertCreated();
    expect($order->json('data.promo_discount'))->toBe('0.00');

    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->tenant->id);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    expect(PromotionResource::canViewAny())->toBeFalse();

    config(['pos.edition' => 'self_hosted']);
    Features::flush();
    expect(Features::enabled('promotions'))->toBeTrue();
});

it('pengingat 7/3/1 hari sekali per ambang; tagihan lewat jatuh tempo kedaluwarsa', function () {
    Notification::fake();
    $owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create());
    $this->tenant->forceFill(['subscription_ends_at' => now()->addDays(3)->setTime(15, 0)])->save();
    $invoice = invoiceFor($this);
    $invoice->forceFill(['due_at' => now()->subHour()])->save();

    expect(app(SendBillingReminders::class)->handle())->toBe(['expired' => 1, 'reminded' => 1])
        ->and(app(SendBillingReminders::class)->handle())->toBe(['expired' => 0, 'reminded' => 0])
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Expired);
    Notification::assertSentTo($owner, SubscriptionEndingSoon::class);
});

it('dashboard: owner membuat tagihan di halaman Langganan; manager tanpa akses; admin menandai lunas', function () {
    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->tenant->id);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    activeOn($this, $this->basic);
    $page = Livewire::test(SubscriptionSettings::class)->instance();
    expect($page->quoteText($this->pro->id, 'monthly'))->toContain('Upgrade Rp48.387')->toContain('sisa 15 hari')
        ->and($page->quoteText($this->basic->id, 'yearly'))->toContain('tetap berlaku sampai 31 Okt 2026');
    $this->tenant->forceFill(['status' => TenantStatus::Trial, 'plan_id' => null, 'billing_cycle' => null, 'limit_overrides' => null, 'subscription_ends_at' => now()->addDays(5)])->save();

    Livewire::test(SubscriptionSettings::class)
        ->assertSee('Semua fitur (tanpa paket)')
        ->callAction('createInvoice', ['plan_id' => $this->basic->id, 'billing_cycle' => 'monthly'])
        ->assertNotified('Tagihan '.SubscriptionInvoice::query()->value('number').' dibuat');

    $this->actingAs(User::factory()->manager()->create());
    expect(SubscriptionSettings::canAccess())->toBeFalse();

    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    $invoice = SubscriptionInvoice::query()->withoutGlobalScopes()->sole();

    Livewire::test(ListSubscriptionInvoices::class)
        ->assertCanSeeTableRecords([$invoice])
        ->callAction(TestAction::make('markPaid')->table($invoice), ['notes' => 'Transfer BCA']);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)->and($invoice->paid_via)->toBe('manual')
        ->and($this->tenant->refresh()->plan_id)->toBe($this->basic->id);
});
