<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tagihan lunas (ADR 0024, Q110): paket & siklus tenant diganti, batas outlet = paket, status aktif, masa aktif
 * ditambah dari max(sekarang, akhir lama). Dari webhook Midtrans atau admin (manual). Lunas ulang = hasil sama.
 */
final class MarkInvoicePaid
{
    public function handle(SubscriptionInvoice $invoice, string $via, ?Admin $admin = null, ?string $notes = null): SubscriptionInvoice
    {
        $result = DB::transaction(function () use ($invoice, $via, $admin, $notes): SubscriptionInvoice {
            $locked = SubscriptionInvoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Paid) {
                return $locked;
            }

            $tenant = Tenant::query()->lockForUpdate()->findOrFail($locked->tenant_id);
            $plan = Plan::query()->findOrFail($locked->plan_id);
            $from = $tenant->subscription_ends_at !== null && $tenant->subscription_ends_at->isFuture() ? $tenant->subscription_ends_at : CarbonImmutable::now();
            $until = $from->addMonthsNoOverflow($locked->billing_cycle->months());

            $tenant->forceFill([
                'plan_id' => $plan->id,
                'billing_cycle' => $locked->billing_cycle,
                'max_outlets' => $plan->max_outlets,
                'status' => $tenant->status === TenantStatus::Suspended ? TenantStatus::Suspended : TenantStatus::Active,
                'subscription_ends_at' => $until,
            ])->save();

            $locked->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'paid_via' => $via,
                'paid_by_admin_id' => $admin?->id,
                'period_start' => $from,
                'period_end' => $until,
                'notes' => $notes ?? $locked->notes,
            ])->save();

            return $locked;
        });

        Features::flush();
        Log::info('Tagihan langganan lunas', ['invoice_id' => $invoice->id, 'via' => $via, 'admin_id' => $admin?->id]);

        return $result;
    }
}
