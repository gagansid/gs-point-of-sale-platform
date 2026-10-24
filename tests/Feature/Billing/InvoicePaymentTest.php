<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateBillingMidtransSettings;
use App\Actions\Admin\UpdateBillingPaymentSettings;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\RejectPaymentProof;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SendBillingReminders;
use App\Actions\Billing\StartInvoicePayment;
use App\Actions\Billing\SubmitPaymentProof;
use App\Enums\BillingCycle;
use App\Enums\InvoicePaymentMethod;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Pages\BillingPaymentSettings;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\Admin;
use App\Models\Bank;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Pembayaran tagihan langganan: pilih QRIS / VA / transfer manual + bukti, verifikasi admin (Q123). */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 03:00:00');
    Storage::fake('local');
    Storage::fake('public');
    $this->pos = posSetup();
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->tenant->forceFill(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)])->save();
    $this->plan = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000']);
    $this->owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create());
    $this->admin = Admin::factory()->withTwoFactor()->create();
});

afterEach(fn () => Carbon::setTestNow());

function payInvoice(object $test, ?Plan $plan = null): SubscriptionInvoice
{
    return TenantContext::run($test->tenant->id, fn () => app(CreateInvoice::class)->handle($test->tenant->refresh(), $plan ?? $test->plan, BillingCycle::Monthly));
}

/** Simpan file bukti di folder bisnis (seperti FileUpload di form). */
function storedProof(string $tenantId, string $name = 'bukti.jpg'): string
{
    return UploadedFile::fake()->image($name)->store(SubmitPaymentProof::directory($tenantId), 'local');
}

function submitProof(object $test, SubscriptionInvoice $invoice, ?string $path = null): SubscriptionInvoice
{
    return TenantContext::run($test->tenant->id, fn () => app(SubmitPaymentProof::class)
        ->handle($invoice, $test->owner, $path ?? storedProof($test->tenant->id), 'Budi · BCA', 'Transfer pagi'));
}

it('QRIS & VA: kanal Snap sesuai metode; metode sama dipakai ulang, ganti metode membatalkan transaksi lama', function () {
    config(['pos.billing.midtrans_server_key' => 'SB-vendor-key']);
    Http::fake([
        'app.sandbox.midtrans.com/*' => Http::sequence()
            ->push(['token' => 't1', 'redirect_url' => 'https://snap.test/qris'])
            ->push(['token' => 't2', 'redirect_url' => 'https://snap.test/va']),
        'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '200']),
    ]);
    $invoice = payInvoice($this);
    $start = fn (InvoicePaymentMethod $m): string => app(StartInvoicePayment::class)->handle($invoice->refresh(), $this->owner, 'https://app.test', $m);

    expect($start(InvoicePaymentMethod::Qris))->toBe('https://snap.test/qris')
        ->and($start(InvoicePaymentMethod::Qris))->toBe('https://snap.test/qris')
        ->and($start(InvoicePaymentMethod::VirtualAccount))->toBe('https://snap.test/va')
        ->and($invoice->refresh()->payment_method)->toBe(InvoicePaymentMethod::VirtualAccount);

    $snaps = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/snap/v1/transactions'))->values();
    expect($snaps)->toHaveCount(2)
        ->and($snaps[0][0]['enabled_payments'])->toContain('other_qris')
        ->and($snaps[1][0]['enabled_payments'])->toContain('bca_va')->not->toContain('other_qris');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/cancel'));

    expect(fn () => app(StartInvoicePayment::class)->handle($invoice, $this->owner, 'https://app.test', InvoicePaymentMethod::Manual))
        ->toThrow(BusinessException::class, 'unggah bukti');
});

it('bukti transfer: tercatat menunggu verifikasi; kirim ulang, path di luar folder bisnis & tagihan tidak menunggu ditolak', function () {
    $invoice = payInvoice($this);

    expect(fn () => submitProof($this, $invoice, 'billing-proofs/tenant-lain/x.jpg'))->toThrow(BusinessException::class, 'tidak ditemukan')
        ->and(fn () => submitProof($this, $invoice, SubmitPaymentProof::directory($this->tenant->id).'/../../.env'))->toThrow(BusinessException::class);

    $invoice = submitProof($this, $invoice);
    expect($invoice->awaitingVerification())->toBeTrue()
        ->and($invoice->payment_method)->toBe(InvoicePaymentMethod::Manual)
        ->and($invoice->proof_sender)->toBe('Budi · BCA')
        ->and($invoice->statusLabel())->toBe('Menunggu verifikasi')
        ->and($invoice->isPayable())->toBeFalse();

    // Request ganda: tetap satu bukti
    expect(fn () => submitProof($this, $invoice))->toThrow(BusinessException::class, 'sedang diverifikasi');

    config(['pos.billing.midtrans_server_key' => 'SB-vendor-key']);
    Http::fake();
    expect(fn () => app(StartInvoicePayment::class)->handle($invoice, $this->owner, 'https://app.test', InvoicePaymentMethod::Qris))
        ->toThrow(BusinessException::class, 'sedang diverifikasi');
    Http::assertNothingSent();
});

it('selama diverifikasi: tidak kedaluwarsa & tagihan baru ditolak agar tagihan tidak ikut dibatalkan', function () {
    $invoice = submitProof($this, payInvoice($this));

    Carbon::setTestNow(now()->addDays(10));
    app(SendBillingReminders::class)->handle();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending)->and($invoice->isOverdue())->toBeFalse()
        ->and(fn () => payInvoice($this))->toThrow(BusinessException::class, 'sedang diverifikasi');
});

it('admin menolak bukti: alasan wajib, owner bisa kirim ulang, jatuh tempo diperpanjang bila lewat, file lama dihapus', function () {
    $invoice = submitProof($this, payInvoice($this));
    $oldPath = $invoice->proof_path;

    expect(fn () => app(RejectPaymentProof::class)->handle($invoice, $this->admin, '  '))->toThrow(BusinessException::class, 'Alasan');

    Carbon::setTestNow(now()->addDays(5));
    $invoice = app(RejectPaymentProof::class)->handle($invoice, $this->admin, 'Dana belum masuk');
    expect($invoice->awaitingVerification())->toBeFalse()->and($invoice->proof_rejected_reason)->toBe('Dana belum masuk')
        ->and($invoice->due_at->isFuture())->toBeTrue()
        ->and(fn () => app(RejectPaymentProof::class)->handle($invoice, $this->admin, 'lagi'))->toThrow(BusinessException::class);

    $invoice = submitProof($this, $invoice);
    expect($invoice->awaitingVerification())->toBeTrue()->and($invoice->proof_rejected_reason)->toBeNull();
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists((string) $invoice->proof_path);
});

it('halaman Langganan: owner kirim bukti lewat modal Bayar; tagihan bisnis lain 404; manager tanpa akses', function () {
    app(SystemSettings::class)->setBillingPayment([['bank_id' => Bank::query()->where('code', 'bca')->value('id'), 'bank' => 'BCA', 'number' => '1234567890', 'holder' => 'PT Abati']], null, null);
    $invoice = payInvoice($this);
    $other = posSetup(openShift: false);
    $otherTenant = Tenant::query()->findOrFail($other->tenantId);
    $foreign = TenantContext::run($otherTenant->id, fn () => app(CreateInvoice::class)->handle($otherTenant, $this->plan, BillingCycle::Monthly));

    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->tenant->id);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    $page = Livewire::test(SubscriptionSettings::class)->assertSee($invoice->number)->assertSee('Bayar sekarang')->assertDontSee($foreign->number);
    expect($page->instance()->paymentMethods())->toBe([InvoicePaymentMethod::Manual]);

    $page->mountAction('payInvoice', ['id' => $invoice->id])
        ->setActionData(['method' => 'manual', 'proof' => UploadedFile::fake()->image('bukti.png'), 'proof_sender' => 'Budi · BCA'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Bukti transfer terkirim');

    expect($invoice->refresh()->awaitingVerification())->toBeTrue()
        ->and(str_starts_with((string) $invoice->proof_path, SubmitPaymentProof::directory($this->tenant->id).'/'))->toBeTrue();
    Livewire::test(SubscriptionSettings::class)->assertSee('Menunggu verifikasi')->assertDontSee('Bayar sekarang');

    rescue(fn () => Livewire::test(SubscriptionSettings::class)->callAction('payInvoice', ['method' => 'manual', 'proof_sender' => 'x'], ['id' => $foreign->id]), report: false);
    expect($foreign->refresh()->proof_submitted_at)->toBeNull();

    $this->actingAs(TenantContext::run($this->tenant->id, fn () => User::factory()->manager()->create()));
    expect(SubscriptionSettings::canAccess())->toBeFalse();
});

it('admin: tab Perlu verifikasi, tolak bukti & tandai lunas', function () {
    $invoice = submitProof($this, payInvoice($this));

    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListSubscriptionInvoices::class)
        ->set('activeTab', 'verify')
        ->assertCanSeeTableRecords([$invoice])
        ->assertActionVisible(TestAction::make('rejectProof')->table($invoice))
        ->callAction(TestAction::make('rejectProof')->table($invoice), ['reason' => 'Nominal kurang'])
        ->assertNotified('Bukti transfer ditolak');
    expect($invoice->refresh()->proof_rejected_reason)->toBe('Nominal kurang');

    $invoice = submitProof($this, $invoice);
    TenantContext::forget();
    Livewire::test(ListSubscriptionInvoices::class)
        ->callAction(TestAction::make('markPaid')->table($invoice), ['notes' => 'Masuk BCA'])
        ->assertNotified('Tagihan lunas');
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)->and($invoice->paid_via)->toBe('manual');
});

it('setelan pembayaran admin: rekening divalidasi & disimpan, QRIS lama dihapus saat diganti', function () {
    $action = app(UpdateBillingPaymentSettings::class);

    $bca = Bank::query()->where('code', 'bca')->sole();
    expect(fn () => $action->handle($this->admin, ['accounts' => [['bank_id' => $bca->id, 'number' => '12ab', 'holder' => 'PT']]]))
        ->toThrow(BusinessException::class, 'tidak valid')
        ->and(fn () => $action->handle($this->admin, ['accounts' => [['bank_id' => 'bukan-id', 'number' => '1234567890', 'holder' => 'PT']]]))
        ->toThrow(BusinessException::class, 'tidak valid')
        ->and(fn () => $action->handle($this->admin, ['qris_image' => '../.env']))->toThrow(BusinessException::class);

    Storage::disk('public')->put('billing/lama.png', 'x');
    $action->handle($this->admin, ['accounts' => [['bank_id' => $bca->id, 'number' => '123 456 7890', 'holder' => 'PT Abati'], ['bank_id' => '', 'number' => '', 'holder' => '']], 'qris_image' => 'billing/lama.png', 'manual_note' => 'Jam kerja']);
    $settings = app(SystemSettings::class);
    expect($settings->billingBankAccounts())->toBe([['bank_id' => $bca->id, 'bank' => 'BCA', 'number' => '1234567890', 'holder' => 'PT Abati']])
        ->and($settings->manualBillingAvailable())->toBeTrue()
        ->and($settings->billingManualNote())->toBe('Jam kerja');

    $action->handle($this->admin, ['accounts' => $settings->billingBankAccounts(), 'qris_image' => null]);
    Storage::disk('public')->assertMissing('billing/lama.png');

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'admin');
    Livewire::test(BillingPaymentSettings::class)->assertOk()->assertSee('Rekening transfer manual');
});

it('Midtrans vendor: mode sandbox/produksi, key per mode diuji & terenkripsi, cadangan .env', function () {
    $action = app(UpdateBillingMidtransSettings::class);
    $settings = app(SystemSettings::class);
    config(['pos.billing.midtrans_server_key' => null]);

    expect($settings->billingMidtransMode())->toBe('sandbox')->and(MidtransClient::vendor())->toBeNull();

    // Midtrans: key berisi "salah" → 401, selain itu 404 (transaksi uji tidak ada = key diterima)
    Http::fake(fn (Request $r) => str_contains(base64_decode(substr((string) $r->header('Authorization')[0], 6)), 'salah')
        ? Http::response(['status_code' => '401'], 401)
        : Http::response(['status_code' => '404'], 404));

    // Format: key sandbox di kolom produksi ditolak tanpa memanggil Midtrans
    expect(fn () => $action->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => 'SB-Mid-server-abcdefgh123']))
        ->toThrow(BusinessException::class, 'Mid-server-');
    Http::assertNothingSent();

    // Key ditolak Midtrans (401) tidak disimpan
    expect(fn () => $action->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => 'Mid-server-salahsekali1']))
        ->toThrow(BusinessException::class, 'ditolak');
    expect($settings->billingMidtransKey('production'))->toBeNull();

    // Key valid: tersimpan terenkripsi, mode produksi memakai API produksi
    expect($action->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => 'Mid-server-benarsekali1', 'midtrans_sandbox_key' => 'SB-Mid-server-benarsekali2']))->toBeTrue();
    $raw = (string) SystemSetting::query()->whereKey(SystemSettings::BILLING_MIDTRANS_KEY_PREFIX.'production')->value('value');
    expect($raw)->not->toContain('benarsekali1')
        ->and($settings->billingMidtransMode())->toBe('production')
        ->and($settings->billingMidtransKey())->toBe('Mid-server-benarsekali1')
        ->and($settings->billingMidtransKey('sandbox'))->toBe('SB-Mid-server-benarsekali2')
        ->and(MidtransClient::vendorServerKey())->toBe('Mid-server-benarsekali1');
    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.midtrans.com/'));
    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.sandbox.midtrans.com/'));

    // Kosong = tidak diubah; hapus = kembali ke .env per mode
    $action->handle($this->admin, ['midtrans_mode' => 'sandbox']);
    expect($settings->billingMidtransKey('production'))->toBe('Mid-server-benarsekali1');
    config(['pos.billing.midtrans_sandbox_server_key' => 'SB-Mid-server-dariEnv000']);
    expect($action->handle($this->admin, ['midtrans_mode' => 'sandbox', 'midtrans_clear_keys' => true]))->toBeTrue()
        ->and($settings->billingMidtransKeySource('sandbox'))->toBe('env')
        ->and($settings->billingMidtransKeySource('production'))->toBeNull()
        ->and($action->handle($this->admin, ['midtrans_mode' => 'production']))->toBeFalse();

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'admin');
    Livewire::test(BillingPaymentSettings::class)->assertOk()->assertSee('Server key sandbox (dev)')->assertDontSee('dariEnv000');
});

it('Midtrans vendor: salinan key dibersihkan dari spasi khusus; Client Key & key mode lain diberi pesan jelas', function () {
    $action = app(UpdateBillingMidtransSettings::class);
    Http::fake(fn () => Http::response(['status_code' => '404'], 404));

    // Client Key tertempel di kolom server key: ditolak tanpa memanggil Midtrans
    expect(fn () => $action->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => 'Mid-client-abcdefgh123']))
        ->toThrow(BusinessException::class, 'Client Key');
    expect(fn () => $action->handle($this->admin, ['midtrans_mode' => 'sandbox', 'midtrans_sandbox_key' => 'Mid-server-abcdefgh123']))
        ->toThrow(BusinessException::class, 'Ini key produksi');
    Http::assertNothingSent();

    // NBSP, zero-width space, dan baris baru dari salinan dashboard tidak membuat key ditolak
    $pasted = "\u{00A0}Mid-server-benar\u{200B}sekali1\n";
    expect($action->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => $pasted]))->toBeTrue()
        ->and(app(SystemSettings::class)->billingMidtransKey('production'))->toBe('Mid-server-benarsekali1');

    // Karakter di luar huruf/angka/_/- (mis. titik, plus, sama dengan) tetap diterima bila Midtrans menerimanya
    expect($action->handle($this->admin, ['midtrans_mode' => 'production', 'midtrans_production_key' => 'Mid-server-pr_uVT.FK+Uab=']))->toBeTrue();
});

it('kolom server key tidak diisi otomatis kata sandi login oleh browser', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'admin');
    Livewire::test(BillingPaymentSettings::class)->assertOk()
        ->assertSeeHtml('autocomplete="new-password"')
        ->assertDontSeeHtml('autocomplete="current-password"');
});

it('webhook tagihan memakai key mode aktif', function () {
    app(SystemSettings::class)->setBillingMidtrans('production', ['production' => 'Mid-server-prodkey00001']);
    $invoice = payInvoice($this);
    $invoice->forceFill(['gateway_order_id' => 'BILL-P'])->save();
    Http::fake(['api.midtrans.com/v2/BILL-P/status' => Http::response(['transaction_status' => 'settlement', 'gross_amount' => '199000.00'])]);

    $payload = ['order_id' => 'BILL-P', 'status_code' => '200', 'gross_amount' => '199000.00'];
    $this->postJson('/webhooks/billing/midtrans', [...$payload, 'signature_key' => MidtransClient::signature('BILL-P', '200', '199000.00', 'SB-vendor-key')])->assertStatus(403);
    $this->postJson('/webhooks/billing/midtrans', [...$payload, 'signature_key' => MidtransClient::signature('BILL-P', '200', '199000.00', 'Mid-server-prodkey00001')])->assertOk();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});
