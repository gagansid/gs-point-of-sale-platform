<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\BillingCycle;
use App\Enums\ErrorCode;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Billing\InvoiceQuote;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buat tagihan langganan (ADR 0024, Q109): paket aktif & publik (atau paket tenant saat ini), siklus bulanan/tahunan,
 * nominal snapshot, jatuh tempo pos.billing.due_days. Tagihan menunggu yang lama dibatalkan (satu per tenant).
 * Jenis & nominal (perpanjangan / upgrade prorata) dari InvoiceQuote.
 */
final class CreateInvoice
{
    /** @throws BusinessException */
    public function handle(Tenant $tenant, Plan $plan, BillingCycle $cycle): SubscriptionInvoice
    {
        if (! Edition::isSaas()) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Edisi jual putus tidak memakai tagihan langganan');
        }

        if (! $plan->is_active || (! $plan->is_public && $tenant->plan_id !== $plan->id)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Paket tidak tersedia', ['plan_id' => ['Paket tidak tersedia']]);
        }

        $quote = InvoiceQuote::for($tenant, $plan, $cycle);
        $dueAt = now()->addDays((int) config('pos.billing.due_days'));
        // Upgrade membayar sisa masa aktif: lewat akhir masa aktif sudah tidak ada yang di-upgrade
        if ($quote->kind === InvoiceKind::Upgrade && $tenant->subscription_ends_at !== null && $tenant->subscription_ends_at->isBefore($dueAt)) {
            $dueAt = $tenant->subscription_ends_at;
        }

        return DB::transaction(function () use ($tenant, $plan, $cycle, $quote, $dueAt): SubscriptionInvoice {
            SubscriptionInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('status', InvoiceStatus::Pending)->lockForUpdate()
                ->update(['status' => InvoiceStatus::Cancelled, 'updated_at' => now()]);

            $invoice = new SubscriptionInvoice([
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $cycle,
                'amount' => $quote->amount,
                'due_at' => $dueAt,
            ]);
            $invoice->tenant_id = $tenant->id;
            $invoice->forceFill(['number' => 'INV-'.now()->format('ym').'-'.Str::upper(Str::random(6)), 'kind' => $quote->kind])->save();

            return $invoice;
        });
    }
}
