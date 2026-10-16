<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Nominal & efek tagihan langganan sebelum dibuat (ADR 0024 revisi 2026-10-16). Dipakai CreateInvoice dan pratinjau
 * di halaman Langganan agar angkanya sama.
 *
 * - Upgrade: langganan berbayar aktif, paket lebih mahal di siklus yang sama → bayar selisih harga × sisa hari ÷ hari
 *   satu siklus; paket langsung berganti saat lunas, masa aktif tidak bertambah.
 * - Perpanjangan: harga penuh, masa aktif ditambah dari max(sekarang, akhir lama). Bila langganan berbayar masih aktif
 *   dan paket/siklus berbeda (downgrade, ganti siklus), paket baru dijadwalkan berlaku di akhir masa aktif lama.
 */
final readonly class InvoiceQuote
{
    public function __construct(
        public InvoiceKind $kind,
        public string $amount,
        // Perpanjangan: awal periode baru; upgrade: sekarang
        public CarbonImmutable $startsAt,
        // Upgrade: sisa hari yang dibayar selisihnya
        public int $remainingDays = 0,
        // Perpanjangan: paket/siklus baru menunggu akhir masa aktif lama
        public bool $scheduled = false,
    ) {}

    public static function for(Tenant $tenant, Plan $plan, BillingCycle $cycle, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $ends = $tenant->subscription_ends_at;
        $current = self::paidPlan($tenant, $now);

        if ($current !== null && $ends !== null && $current->id !== $plan->id && $tenant->billing_cycle === $cycle
            && Money::compare($plan->price($cycle), $current->price($cycle)) > 0) {
            $cycleDays = max(1, (int) round($ends->subMonthsNoOverflow($cycle->months())->diffInDays($ends)));
            $remaining = max(1, (int) ceil($now->diffInDays($ends)));
            // Dibulatkan ke rupiah penuh
            $amount = Money::of(Money::round(bcdiv(bcmul(Money::sub($plan->price($cycle), $current->price($cycle)), (string) $remaining, 6), (string) $cycleDays, 6), 0));

            return new self(InvoiceKind::Upgrade, $amount, $now, remainingDays: $remaining);
        }

        $startsAt = $ends !== null && $ends->isAfter($now) ? $ends : $now;

        return new self(
            InvoiceKind::Renewal,
            Money::of($plan->price($cycle)),
            $startsAt,
            scheduled: $current !== null && ($current->id !== $plan->id || $tenant->billing_cycle !== $cycle),
        );
    }

    /** Paket berbayar yang sedang berjalan (status aktif, masa aktif belum habis); trial/habis = null. */
    private static function paidPlan(Tenant $tenant, CarbonImmutable $now): ?Plan
    {
        if ($tenant->status !== TenantStatus::Active || $tenant->plan_id === null || $tenant->billing_cycle === null
            || $tenant->subscription_ends_at === null || ! $tenant->subscription_ends_at->isAfter($now)) {
            return null;
        }

        return Plan::query()->find($tenant->plan_id);
    }
}
