<?php

declare(strict_types=1);

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Actions\Billing\PrunePaymentProofs;
use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SubmitPaymentProof;
use App\Enums\BillingCycle;
use App\Enums\TenantStatus;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** pos:prune-payment-proofs (Q127): bukti tagihan batal/kedaluwarsa lewat masa simpan & file yatim dihapus. */
beforeEach(function () {
    Carbon::setTestNow('2026-10-21 03:00:00');
    Storage::fake('local');
    Notification::fake();
    config(['pos.billing.proof_retention_days' => 90]);
    $this->pos = posSetup();
    $this->tenant = Tenant::query()->findOrFail($this->pos->tenantId);
    $this->tenant->forceFill(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)])->save();
    $this->owner = TenantContext::run($this->tenant->id, fn () => User::factory()->owner()->create());
});

afterEach(fn () => Carbon::setTestNow());

function pruneInvoiceWithProof(object $test, string $code): SubscriptionInvoice
{
    $plan = app(SavePlan::class)->handle(null, ['code' => $code, 'name' => ucfirst($code), 'price_monthly' => '100000', 'price_yearly' => '1000000']);
    $invoice = TenantContext::run($test->tenant->id, fn () => app(CreateInvoice::class)->handle($test->tenant->refresh(), $plan, BillingCycle::Monthly));
    $path = UploadedFile::fake()->image($code.'.jpg')->store(SubmitPaymentProof::directory($test->tenant->id), 'local');

    return TenantContext::run($test->tenant->id, fn () => app(SubmitPaymentProof::class)->handle($invoice, $test->owner, $path, 'Budi'));
}

it('menghapus bukti tagihan batal yang lewat masa simpan; bukti lunas, menunggu, dan yang masih baru tetap', function () {
    $old = pruneInvoiceWithProof($this, 'lama');
    app(CancelInvoice::class)->handle($old);
    $paid = pruneInvoiceWithProof($this, 'lunas');
    app(MarkInvoicePaid::class)->handle($paid, 'manual');
    $recentCancelled = pruneInvoiceWithProof($this, 'baru');

    Carbon::setTestNow(now()->addDays(91));
    app(CancelInvoice::class)->handle($recentCancelled); // dibatalkan hari ini: belum lewat masa simpan
    $pending = pruneInvoiceWithProof($this, 'menunggu');

    $this->artisan('pos:prune-payment-proofs')->expectsOutputToContain('1 bukti tagihan lama')->assertSuccessful();

    Storage::disk('local')->assertMissing((string) $old->proof_path);
    expect($old->refresh()->proof_path)->toBeNull()
        ->and($old->status->value)->toBe('cancelled');
    foreach ([$paid, $recentCancelled, $pending] as $kept) {
        Storage::disk('local')->assertExists((string) $kept->refresh()->proof_path);
    }

    // Aman diulang
    $this->artisan('pos:prune-payment-proofs')->expectsOutputToContain('0 bukti tagihan lama & 0 file yatim')->assertSuccessful();
});

it('menghapus unggahan yatim lebih dari 1 hari, unggahan baru dibiarkan', function () {
    $dir = SubmitPaymentProof::directory($this->tenant->id);
    Storage::disk('local')->put($dir.'/yatim-lama.jpg', 'x');
    touch(Storage::disk('local')->path($dir.'/yatim-lama.jpg'), now()->subDays(2)->getTimestamp());
    Storage::disk('local')->put($dir.'/yatim-baru.jpg', 'x');
    $referenced = pruneInvoiceWithProof($this, 'dirujuk');
    touch(Storage::disk('local')->path((string) $referenced->proof_path), now()->subDays(5)->getTimestamp());

    expect(app(PrunePaymentProofs::class)->handle())->toBe(['expired' => 0, 'orphans' => 1]);
    Storage::disk('local')->assertMissing($dir.'/yatim-lama.jpg');
    Storage::disk('local')->assertExists($dir.'/yatim-baru.jpg');
    Storage::disk('local')->assertExists((string) $referenced->proof_path);
});
