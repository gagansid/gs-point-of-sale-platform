<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Billing\InvoiceQuote;
use App\Services\Billing\TenantAddons;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Tagihan lunas (ADR 0024, Q110): paket & siklus tenant diganti, batas outlet = paket, status aktif, masa aktif
 * ditambah dari max(sekarang, akhir lama). Dari webhook Midtrans atau admin (manual). Lunas ulang = hasil sama.
 * Revisi 2026-10-16: tagihan upgrade mengganti paket tanpa menambah masa aktif; perpanjangan dengan paket/siklus lain
 * selagi langganan berbayar berjalan dijadwalkan (next_plan_id) dan diterapkan ApplyScheduledPlanChanges.
 * ADR 0025 C2: tagihan add-on memasang add-on tanpa mengubah paket/masa aktif.
 * Tagihan manual admin: hanya baris add-on yang dipasang; paket, status & masa aktif tetap; periode kosong.
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
            $now = CarbonImmutable::now();

            if ($locked->kind === InvoiceKind::Manual) {
                // Tagihan manual: paket & masa aktif tetap; baris add-on (bila ada) dipasang, biaya bebas tidak mengubah apa pun
                $from = null;
                $until = null;
                TenantAddons::attachFromInvoice($locked);
            } elseif ($locked->kind === InvoiceKind::Addon) {
                // Tambah add-on (ADR 0025 C2): paket & masa aktif tetap; add-on aktif sampai akhir masa aktif
                $from = $now;
                $until = $tenant->subscription_ends_at !== null && $tenant->subscription_ends_at->isAfter($now) ? $tenant->subscription_ends_at : $now;
                TenantAddons::attachFromInvoice($locked);
            } elseif ($locked->kind === InvoiceKind::Upgrade) {
                // Upgrade prorata: paket langsung berganti, masa aktif tetap
                $from = $now;
                $until = $tenant->subscription_ends_at !== null && $tenant->subscription_ends_at->isAfter($now) ? $tenant->subscription_ends_at : $now;
                $tenant->forceFill(['plan_id' => (string) $locked->plan_id, 'limit_overrides' => null]);
            } else {
                $plan = Plan::query()->findOrFail($locked->plan_id);
                $cycle = $locked->billing_cycle ?? throw new LogicException('Tagihan paket tanpa siklus');
                $quote = InvoiceQuote::for($tenant, $plan, $cycle, $now);
                $from = $quote->startsAt;
                $until = $from->addMonthsNoOverflow($cycle->months());

                // Downgrade / ganti siklus selagi langganan berbayar berjalan: berlaku di akhir masa aktif lama
                $tenant->forceFill($quote->scheduled
                    ? ['next_plan_id' => $plan->id, 'next_billing_cycle' => $cycle, 'plan_changes_at' => $tenant->plan_changes_at ?? $from]
                    : [
                        'plan_id' => $plan->id,
                        'billing_cycle' => $cycle,
                        // Paket diterapkan: batas mengikuti paket (batas khusus admin dihapus, ADR 0025)
                        'limit_overrides' => null,
                        'next_plan_id' => null, 'next_billing_cycle' => null, 'plan_changes_at' => null,
                    ]);
                $tenant->forceFill([
                    'status' => $tenant->status === TenantStatus::Suspended ? TenantStatus::Suspended : TenantStatus::Active,
                    'subscription_ends_at' => $until,
                ]);
            }

            $tenant->save();
            // Paket baru mungkin sudah memuat fitur add-on: add-on itu berhenti di akhir masa aktif
            TenantAddons::cancelRedundant($tenant, $now);

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
