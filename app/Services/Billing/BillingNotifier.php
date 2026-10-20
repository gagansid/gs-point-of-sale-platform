<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvoicePaid;
use App\Notifications\PaymentProofRejected;
use App\Notifications\PaymentProofSubmitted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Email pembayaran tagihan langganan (Q126), lewat antrean database & setelah transaksi commit (email gagal tidak
 * membatalkan pembayaran): bukti dikirim → semua super admin; bukti ditolak / lunas → owner aktif ber-email bisnis itu.
 */
final class BillingNotifier
{
    public function proofSubmitted(SubscriptionInvoice $invoice): void
    {
        DB::afterCommit(function () use ($invoice): void {
            $tenant = Tenant::query()->find($invoice->tenant_id);
            Admin::query()->whereNotNull('email')->get()
                ->each(fn (Admin $admin) => $admin->notify(new PaymentProofSubmitted($invoice, $tenant)));
        });
    }

    public function proofRejected(SubscriptionInvoice $invoice): void
    {
        DB::afterCommit(fn () => self::owners($invoice->tenant_id)
            ->each(fn (User $owner) => $owner->notify(new PaymentProofRejected($invoice, Tenant::query()->find($invoice->tenant_id)))));
    }

    public function paid(SubscriptionInvoice $invoice): void
    {
        DB::afterCommit(fn () => self::owners($invoice->tenant_id)
            ->each(fn (User $owner) => $owner->notify(new InvoicePaid($invoice, Tenant::query()->find($invoice->tenant_id)))));
    }

    /** @return Collection<int, User> */
    private static function owners(string $tenantId): Collection
    {
        return User::allTenants()->where('tenant_id', $tenantId)->where('role', UserRole::Owner)->where('is_active', true)
            ->whereNotNull('email')->get();
    }
}
