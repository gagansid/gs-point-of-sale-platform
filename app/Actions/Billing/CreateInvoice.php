<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\BillingCycle;
use App\Enums\ErrorCode;
use App\Enums\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buat tagihan langganan (ADR 0024, Q109): paket aktif & publik (atau paket tenant saat ini), siklus bulanan/tahunan,
 * nominal snapshot, jatuh tempo pos.billing.due_days. Tagihan menunggu yang lama dibatalkan (satu per tenant).
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

        return DB::transaction(function () use ($tenant, $plan, $cycle): SubscriptionInvoice {
            SubscriptionInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('status', InvoiceStatus::Pending)->lockForUpdate()
                ->update(['status' => InvoiceStatus::Cancelled, 'updated_at' => now()]);

            $invoice = new SubscriptionInvoice([
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $cycle,
                'amount' => $plan->price($cycle),
                'due_at' => now()->addDays((int) config('pos.billing.due_days')),
            ]);
            $invoice->tenant_id = $tenant->id;
            $invoice->forceFill(['number' => 'INV-'.now()->format('ym').'-'.Str::upper(Str::random(6))])->save();

            return $invoice;
        });
    }
}
