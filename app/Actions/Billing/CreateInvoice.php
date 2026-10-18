<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\BillingCycle;
use App\Enums\ErrorCode;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvoiceCreated;
use App\Services\Billing\InvoiceQuote;
use App\Support\Edition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buat tagihan langganan (ADR 0024, Q109): paket aktif & publik (atau paket tenant saat ini), siklus bulanan/tahunan,
 * nominal snapshot, jatuh tempo pos.billing.due_days. Tagihan menunggu yang lama dibatalkan (satu per tenant).
 * Jenis, baris (paket + add-on aktif, ADR 0025 C2) & nominal dari InvoiceQuote.
 *
 * Dibuat super admin ($admin): boleh paket aktif non-publik (penawaran khusus), pembuat tercatat, dan owner aktif
 * bisa diberi tahu lewat email ($notifyOwners).
 */
final class CreateInvoice
{
    /** @throws BusinessException */
    public function handle(Tenant $tenant, Plan $plan, BillingCycle $cycle, ?Admin $admin = null, bool $notifyOwners = false): SubscriptionInvoice
    {
        self::ensureSaas();

        if (! $plan->is_active || (! $plan->is_public && $admin === null && $tenant->plan_id !== $plan->id)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Paket tidak tersedia', ['plan_id' => ['Paket tidak tersedia']]);
        }

        return self::persist($tenant, $plan, $cycle, InvoiceQuote::for($tenant, $plan, $cycle), $admin, $notifyOwners);
    }

    /** @throws BusinessException */
    public static function ensureSaas(): void
    {
        if (! Edition::isSaas()) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Edisi jual putus tidak memakai tagihan langganan');
        }
    }

    /**
     * Simpan tagihan + baris dari quote; tagihan menunggu lama dibatalkan. $plan = paket konteks tagihan (untuk
     * tagihan add-on: paket tenant saat ini).
     */
    public static function persist(Tenant $tenant, Plan $plan, BillingCycle $cycle, InvoiceQuote $quote, ?Admin $admin, bool $notifyOwners): SubscriptionInvoice
    {
        $dueAt = CarbonImmutable::now()->addDays((int) config('pos.billing.due_days'));
        // Upgrade/add-on membayar sisa masa aktif: lewat akhir masa aktif sudah tidak ada yang dibayar
        if (in_array($quote->kind, [InvoiceKind::Upgrade, InvoiceKind::Addon], true) && $tenant->subscription_ends_at !== null && $tenant->subscription_ends_at->isBefore($dueAt)) {
            $dueAt = $tenant->subscription_ends_at;
        }

        $invoice = DB::transaction(function () use ($tenant, $plan, $cycle, $quote, $dueAt, $admin): SubscriptionInvoice {
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
            $invoice->forceFill([
                'number' => 'INV-'.now()->format('ym').'-'.Str::upper(Str::random(6)),
                'kind' => $quote->kind,
                'created_by_admin_id' => $admin?->id,
            ])->save();

            foreach ($quote->lines as $index => $line) {
                $invoice->items()->create([...$line, 'sort_order' => $index]);
            }

            return $invoice->load('items');
        });

        // Setelah commit: email gagal tidak membatalkan tagihan
        if ($notifyOwners) {
            User::allTenants()->where('tenant_id', $tenant->id)->where('role', UserRole::Owner)->where('is_active', true)
                ->whereNotNull('email')->get()
                ->each(fn (User $owner) => $owner->notify(new InvoiceCreated($invoice, $tenant)));
        }

        return $invoice;
    }
}
