<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Addon;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceItem;
use App\Models\Tenant;
use App\Models\TenantAddon;
use Carbon\CarbonImmutable;

/**
 * Siklus hidup add-on bisnis (ADR 0025 C2). Dipanggil di dalam transaksi Action billing (MarkInvoicePaid,
 * SetTenantPlan, ApplyScheduledPlanChanges); tidak membuka transaksi sendiri.
 */
final class TenantAddons
{
    /** Tagihan add-on lunas: pasang add-on atau tambah unit (kuota); add-on yang sedang berhenti aktif lagi. */
    public static function attachFromInvoice(SubscriptionInvoice $invoice): void
    {
        foreach ($invoice->items()->where('type', SubscriptionInvoiceItem::ADDON)->whereNotNull('addon_id')->get() as $item) {
            $addon = Addon::query()->find($item->addon_id);
            if ($addon === null) {
                continue;
            }

            $row = TenantAddon::forTenant($invoice->tenant_id)->where('addon_id', $addon->id)->lockForUpdate()->first()
                ?? new TenantAddon(['addon_id' => $addon->id, 'quantity' => 0]);
            $row->tenant_id = $invoice->tenant_id;
            $row->quantity = $addon->isQuota() ? $row->quantity + $item->quantity : 1;
            $row->forceFill(['status' => TenantAddon::ACTIVE, 'cancel_at' => null])->save();
        }
    }

    /**
     * Add-on fitur yang kini termasuk paket tenant tidak perlu lagi: berhenti di akhir masa aktif (sudah dibayar),
     * atau langsung dilepas bila masa aktif sudah lewat.
     */
    public static function cancelRedundant(Tenant $tenant, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $features = $tenant->plan_id !== null ? ($tenant->plan()->first()->features ?? []) : [];

        foreach (TenantAddon::forTenant($tenant->id)->with('addon')->get() as $row) {
            if ($row->addon === null || $row->addon->isQuota() || ! in_array($row->addon->feature_key, $features, true)) {
                continue;
            }

            self::stop($row, $tenant, $now);
        }

        $tenant->unsetRelation('addons');
    }

    /** Berhenti di akhir masa aktif berbayar; tanpa masa aktif berjalan = langsung dilepas. */
    public static function stop(TenantAddon $row, Tenant $tenant, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $ends = $tenant->subscription_ends_at;

        if ($ends === null || ! $ends->isAfter($now)) {
            $row->delete();

            return;
        }

        $row->forceFill(['status' => TenantAddon::CANCELLING, 'cancel_at' => $row->cancel_at ?? $ends])->save();
    }

    /** Lepas add-on berhenti yang sudah lewat waktunya (pos:apply-plan-changes tiap jam). */
    public static function releaseEnded(?CarbonImmutable $now = null): int
    {
        return TenantAddon::allTenants()->where('status', TenantAddon::CANCELLING)
            ->where('cancel_at', '<=', $now ?? CarbonImmutable::now())->delete();
    }
}
