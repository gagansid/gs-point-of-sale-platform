<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\BillingCycle;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Features;

/**
 * Admin mengatur paket tenant tanpa tagihan (ADR 0024), mis. pelanggan lama / kesepakatan khusus. Paket null = semua
 * fitur. Kuota mengikuti paket (ADR 0025); masa aktif tidak berubah.
 */
final class SetTenantPlan
{
    public function handle(Tenant $tenant, ?Plan $plan, ?BillingCycle $cycle): Tenant
    {
        $tenant->forceFill([
            'plan_id' => $plan?->id,
            'billing_cycle' => $plan !== null ? $cycle : null,
            // Batas mengikuti paket (batas khusus admin dihapus); tanpa paket batas khusus tetap
            'limit_overrides' => $plan !== null ? null : $tenant->limit_overrides,
            // Diatur langsung oleh admin: jadwal ganti paket dari tagihan dibatalkan
            'next_plan_id' => null,
            'next_billing_cycle' => null,
            'plan_changes_at' => null,
        ])->save();

        Features::flush();

        return $tenant;
    }
}
