<?php

declare(strict_types=1);

use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Actions\Billing\RejectPaymentProof;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SubmitPaymentProof;
use App\Enums\BillingCycle;
use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvoicePaid;
use App\Notifications\PaymentProofRejected;
use App\Notifications\PaymentProofSubmitted;
use App\Services\Billing\InvoicePdf;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\Terbilang;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Dokumen PDF tagihan/kuitansi & email pembayaran tagihan langganan (Q126). */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 03:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->pos = posSetup();
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->tenant->forceFill(['name' => 'Kopi Senja', 'status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)])->save();
    $this->plan = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000']);
    $this->owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create(['email' => 'owner@kopi.test']));
    $this->admin = Admin::factory()->withTwoFactor()->create();
});

afterEach(fn () => Carbon::setTestNow());

function docInvoice(object $test, ?Plan $plan = null): SubscriptionInvoice
{
    return TenantContext::run($test->tenant->id, fn () => app(CreateInvoice::class)->handle($test->tenant->refresh(), $plan ?? $test->plan, BillingCycle::Monthly));
}

function docProof(object $test, SubscriptionInvoice $invoice): SubscriptionInvoice
{
    $path = UploadedFile::fake()->image('bukti.jpg')->store(SubmitPaymentProof::directory($test->tenant->id), 'local');

    return TenantContext::run($test->tenant->id, fn () => app(SubmitPaymentProof::class)->handle($invoice, $test->owner, $path, 'Budi · BCA'));
}

function docHtml(SubscriptionInvoice $invoice): string
{
    $invoice->refresh()->load('items');

    return view('billing.pdf.invoice', [
        'invoice' => $invoice, 'tenant' => Tenant::query()->find($invoice->tenant_id), 'owner' => null,
        'receipt' => InvoicePdf::isReceipt($invoice), 'issuer' => ['name' => 'PT Abati', 'email' => 'billing@gspos.test'],
        'timezone' => 'Asia/Jakarta', 'manual' => [],
    ])->render();
}

it('terbilang rupiah Bahasa Indonesia', function () {
    expect(Terbilang::rupiah('397000.00'))->toBe('Tiga ratus sembilan puluh tujuh ribu rupiah')
        ->and(Terbilang::rupiah('1011000'))->toBe('Satu juta sebelas ribu rupiah')
        ->and(Terbilang::rupiah('115'))->toBe('Seratus lima belas rupiah')
        ->and(Terbilang::rupiah('0'))->toBe('Nol rupiah');
});

it('PDF: sebelum lunas = Tagihan dengan cara bayar, setelah lunas = Kuitansi bercap LUNAS & terbilang', function () {
    $invoice = docInvoice($this);

    $html = docHtml($invoice);
    expect($html)->toContain('Tagihan '.$invoice->number)->toContain('Cara pembayaran')->toContain('Jatuh tempo')
        ->toContain('Seratus sembilan puluh sembilan ribu rupiah')->not->toContain('>LUNAS<')
        ->and(InvoicePdf::filename($invoice))->toBe('tagihan-'.$invoice->number.'.pdf')
        ->and(app(InvoicePdf::class)->render($invoice))->toStartWith('%PDF');

    app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin);
    $invoice->refresh();
    expect(docHtml($invoice))->toContain('Kuitansi '.$invoice->number)->toContain('>LUNAS<')->toContain('Informasi pembayaran')
        ->not->toContain('Cara pembayaran')
        ->and(InvoicePdf::filename($invoice))->toBe('kuitansi-'.$invoice->number.'.pdf');
});

it('owner mengunduh PDF tagihannya sendiri; tagihan bisnis lain 404', function () {
    $invoice = docInvoice($this);
    $other = posSetup(openShift: false);
    $otherTenant = Tenant::query()->findOrFail($other->tenantId);
    $foreign = TenantContext::run($otherTenant->id, fn () => app(CreateInvoice::class)->handle($otherTenant, $this->plan, BillingCycle::Monthly));

    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->tenant->id);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    Livewire::test(SubscriptionSettings::class)
        ->callAction('downloadPdf', arguments: ['id' => $invoice->id])
        ->assertFileDownloaded('tagihan-'.$invoice->number.'.pdf');

    rescue(fn () => Livewire::test(SubscriptionSettings::class)->callAction('downloadPdf', arguments: ['id' => $foreign->id])->assertNoFileDownloaded(), report: false);
    expect(fn () => (fn () => $this->download($foreign->id))->call(Livewire::test(SubscriptionSettings::class)->instance()))
        ->toThrow(ModelNotFoundException::class);
});

it('admin mengunduh kuitansi dari daftar tagihan', function () {
    $invoice = docInvoice($this);
    app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin);

    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListSubscriptionInvoices::class)
        ->callAction(TestAction::make('downloadPdf')->table($invoice))
        ->assertFileDownloaded('kuitansi-'.$invoice->number.'.pdf');
});

it('email: bukti dikirim → admin; ditolak → owner; lunas → owner sekali dengan kuitansi PDF', function () {
    $other = Admin::factory()->create();
    $invoice = docProof($this, docInvoice($this));

    Notification::assertSentTo([$this->admin, $other], PaymentProofSubmitted::class,
        fn (PaymentProofSubmitted $n): bool => $n->number === $invoice->number && $n->sender === 'Budi · BCA' && $n->businessName === 'Kopi Senja');
    Notification::assertNothingSentTo($this->owner);

    app(RejectPaymentProof::class)->handle($invoice, $this->admin, 'Nominal kurang Rp1.000');
    Notification::assertSentTo($this->owner, PaymentProofRejected::class, fn (PaymentProofRejected $n): bool => $n->reason === 'Nominal kurang Rp1.000');
    $mail = (new PaymentProofRejected($invoice->refresh(), $this->tenant))->toMail($this->owner);
    expect($mail->subject)->toContain($invoice->number)->and(implode(' ', $mail->introLines))->toContain('Nominal kurang Rp1.000');

    $invoice = docProof($this, $invoice);
    app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin);
    app(MarkInvoicePaid::class)->handle($invoice, 'manual', $this->admin);
    Notification::assertSentToTimes($this->owner, InvoicePaid::class, 1);

    $paidMail = (new InvoicePaid($invoice->refresh(), $this->tenant))->toMail($this->owner);
    expect($paidMail->subject)->toBe('Pembayaran diterima — '.$invoice->number)
        ->and($paidMail->rawAttachments)->toHaveCount(1)
        ->and($paidMail->rawAttachments[0]['name'])->toBe('kuitansi-'.$invoice->number.'.pdf')
        ->and($paidMail->rawAttachments[0]['data'])->toStartWith('%PDF');
});

it('riwayat tagihan: tabel bisa dicari, difilter, dan hanya berisi tagihan bisnis sendiri', function () {
    $paid = docInvoice($this);
    app(MarkInvoicePaid::class)->handle($paid, 'manual', $this->admin);
    $pending = docInvoice($this);

    Filament::setCurrentPanel('dashboard');
    TenantContext::set($this->tenant->id);
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);

    Livewire::test(SubscriptionSettings::class)
        ->assertCanSeeTableRecords([$paid, $pending])
        ->searchTable($pending->number)
        ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$paid])
        ->searchTable(null)
        ->filterTable('status', 'paid')
        ->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$pending])
        ->callTableAction('pdf', $paid)
        ->assertFileDownloaded('kuitansi-'.$paid->number.'.pdf');
});
