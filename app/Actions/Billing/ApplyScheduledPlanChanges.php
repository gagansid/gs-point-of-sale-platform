<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paket terjadwal (ADR 0024 revisi): perpanjangan dengan paket/siklus lain yang dibayar selagi langganan berjalan
 * berlaku saat plan_changes_at tercapai. Dijalankan tiap jam (pos:apply-plan-changes). Jalan ulang = hasil sama.
 */
final class ApplyScheduledPlanChanges
{
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $applied = 0;

        $ids = Tenant::query()->whereNotNull('next_plan_id')->where('plan_changes_at', '<=', $now)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$applied): void {
                $tenant = Tenant::query()->lockForUpdate()->find($id);

                if ($tenant === null || $tenant->next_plan_id === null || $tenant->plan_changes_at === null || $tenant->plan_changes_at->isAfter($now)) {
                    return;
                }

                $plan = Plan::query()->find($tenant->next_plan_id);
                $tenant->forceFill([
                    'plan_id' => $plan->id ?? $tenant->plan_id,
                    'billing_cycle' => $tenant->next_billing_cycle ?? $tenant->billing_cycle,
                    'limit_overrides' => $plan !== null ? null : $tenant->limit_overrides,
                    'next_plan_id' => null,
                    'next_billing_cycle' => null,
                    'plan_changes_at' => null,
                ])->save();
                $applied++;

                Log::info('Paket terjadwal diterapkan', ['tenant_id' => $tenant->id, 'plan_id' => $tenant->plan_id]);
            });
        }

        if ($applied > 0) {
            Features::flush();
        }

        return $applied;
    }
}
