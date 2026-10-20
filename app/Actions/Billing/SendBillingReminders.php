<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionEndingSoon;
use App\Support\Edition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Jadwal harian (ADR 0024, Q111): tagihan menunggu lewat jatuh tempo → kedaluwarsa; email owner aktif 7/3/1 hari
 * sebelum langganan/trial berakhir (sekali per tenant per ambang, kunci cache 40 hari).
 *
 * @return array{expired: int, reminded: int}
 */
final class SendBillingReminders
{
    /** @return array{expired: int, reminded: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        // Bukti transfer yang sedang diverifikasi admin tidak membuat tagihan kedaluwarsa (Q123)
        $expired = SubscriptionInvoice::query()->withoutGlobalScopes()->where('status', InvoiceStatus::Pending)
            ->where('due_at', '<', $now)->whereNull('proof_submitted_at')->update(['status' => InvoiceStatus::Expired, 'updated_at' => $now]);

        if (! Edition::isSaas()) {
            return ['expired' => $expired, 'reminded' => 0];
        }

        $reminded = 0;
        foreach ((array) config('pos.billing.reminder_days') as $days) {
            $day = $now->addDays((int) $days);
            $tenants = Tenant::query()->where('status', '!=', TenantStatus::Suspended)
                ->whereBetween('subscription_ends_at', [$day->startOfDay(), $day->endOfDay()])->get();

            foreach ($tenants as $tenant) {
                $key = 'billing-reminder:'.$tenant->id.':'.$tenant->subscription_ends_at?->toDateString().':'.$days;
                if (! Cache::add($key, true, now()->addDays(40))) {
                    continue;
                }

                User::allTenants()->where('tenant_id', $tenant->id)->where('role', UserRole::Owner)->where('is_active', true)
                    ->whereNotNull('email')->get()
                    ->each(fn (User $owner) => $owner->notify(new SubscriptionEndingSoon($tenant, (int) $days)));
                $reminded++;
            }
        }

        return ['expired' => $expired, 'reminded' => $reminded];
    }
}
