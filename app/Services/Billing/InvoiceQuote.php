<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingCycle;
use App\Enums\ErrorCode;
use App\Enums\InvoiceKind;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\SubscriptionInvoiceItem;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Nominal, baris & efek tagihan langganan sebelum dibuat (ADR 0024 revisi, ADR 0025 C2). Dipakai CreateInvoice,
 * CreateAddonInvoice dan pratinjau di modal agar angkanya sama.
 *
 * - Upgrade: langganan berbayar aktif, paket lebih mahal di siklus yang sama → bayar selisih harga × sisa hari ÷ hari
 *   satu siklus; paket langsung berganti saat lunas, masa aktif tidak bertambah. Add-on tidak ikut (masa aktif sama).
 * - Perpanjangan: harga penuh paket + add-on aktif (yang sedang berhenti, atau fiturnya sudah termasuk paket, tidak
 *   ikut); masa aktif ditambah dari max(sekarang, akhir lama). Bila langganan berbayar masih aktif dan paket/siklus
 *   berbeda (downgrade, ganti siklus), paket baru dijadwalkan berlaku di akhir masa aktif lama.
 * - Add-on: tambah add-on di tengah masa aktif → harga × unit × sisa hari ÷ hari satu siklus (siklus tenant).
 */
final readonly class InvoiceQuote
{
    /**
     * @param  list<array{type: string, plan_id: string|null, addon_id: string|null, name: string, quantity: int, unit_price: string, amount: string}>  $lines
     */
    public function __construct(
        public InvoiceKind $kind,
        public string $amount,
        // Perpanjangan: awal periode baru; upgrade/add-on: sekarang
        public CarbonImmutable $startsAt,
        // Upgrade/add-on: sisa hari yang dibayar
        public int $remainingDays = 0,
        // Perpanjangan: paket/siklus baru menunggu akhir masa aktif lama
        public bool $scheduled = false,
        public array $lines = [],
    ) {}

    public static function for(Tenant $tenant, Plan $plan, BillingCycle $cycle, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $ends = $tenant->subscription_ends_at;
        $current = self::paidPlan($tenant, $now);

        if ($current !== null && $ends !== null && $current->id !== $plan->id && $tenant->billing_cycle === $cycle
            && Money::compare($plan->price($cycle), $current->price($cycle)) > 0) {
            [$amount, $remaining] = self::prorate(Money::sub($plan->price($cycle), $current->price($cycle)), $ends, $cycle, $now);
            $lines = [self::line(SubscriptionInvoiceItem::PLAN, $plan->id, null, "Upgrade paket {$plan->name} (sisa {$remaining} hari)", 1, $amount)];

            return new self(InvoiceKind::Upgrade, $amount, $now, remainingDays: $remaining, lines: $lines);
        }

        $startsAt = $ends !== null && $ends->isAfter($now) ? $ends : $now;
        $lines = [self::line(SubscriptionInvoiceItem::PLAN, $plan->id, null, "Paket {$plan->name}", 1, Money::of($plan->price($cycle)))];

        foreach (self::renewableAddons($tenant, $plan) as $owned) {
            $lines[] = self::line(SubscriptionInvoiceItem::ADDON, null, $owned->addon_id, $owned->addon->name, $owned->quantity, Money::of($owned->addon->price($cycle)));
        }

        return new self(
            InvoiceKind::Renewal,
            Money::add(...array_column($lines, 'amount')),
            $startsAt,
            scheduled: $current !== null && ($current->id !== $plan->id || $tenant->billing_cycle !== $cycle),
            lines: $lines,
        );
    }

    /**
     * Tambah add-on di tengah masa aktif langganan berbayar (prorata, siklus tenant).
     *
     * @throws BusinessException VALIDATION_ERROR bila belum ada langganan berbayar yang berjalan
     */
    public static function forAddon(Tenant $tenant, Addon $addon, int $quantity, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $plan = self::paidPlan($tenant, $now);
        $cycle = $tenant->billing_cycle;

        if ($plan === null || $cycle === null || $tenant->subscription_ends_at === null) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Add-on bisa dibeli setelah langganan berbayar aktif. Saat trial, buat tagihan paket dulu.', ['addon_id' => ['Belum ada langganan berbayar yang berjalan']]);
        }

        [$amount, $remaining] = self::prorate(Money::mul($addon->price($cycle), $quantity), $tenant->subscription_ends_at, $cycle, $now);
        $line = self::line(SubscriptionInvoiceItem::ADDON, null, $addon->id, "{$addon->name} (sisa {$remaining} hari)", $quantity, Money::div($amount, $quantity));
        // Nominal baris = total prorata (dibulatkan sekali), harga satuan hanya informasi
        $line['amount'] = $amount;
        $lines = [$line];

        return new self(InvoiceKind::Addon, $amount, $now, remainingDays: $remaining, lines: $lines);
    }

    /** Pratinjau nominal & efek tagihan (modal Buat tagihan owner & admin). */
    public function summary(Plan $plan, string $timezone): string
    {
        $date = $this->startsAt->setTimezone($timezone)->locale('id')->translatedFormat('j M Y');
        $addons = array_filter($this->lines, fn (array $l): bool => $l['type'] === SubscriptionInvoiceItem::ADDON);
        $withAddons = $addons === [] ? '' : ' (termasuk add-on: '.implode(', ', array_map(fn (array $l): string => $l['name'].($l['quantity'] > 1 ? ' ×'.$l['quantity'] : ''), $addons)).')';

        return match (true) {
            $this->kind === InvoiceKind::Upgrade => 'Upgrade '.Money::format($this->amount).' (selisih harga untuk sisa '.$this->remainingDays.' hari). Paket langsung berganti setelah lunas; masa aktif tetap',
            $this->kind === InvoiceKind::Addon => 'Add-on '.Money::format($this->amount).' untuk sisa '.$this->remainingDays.' hari masa aktif. Aktif setelah lunas; berikutnya ikut tagihan perpanjangan',
            $this->scheduled => 'Perpanjangan '.Money::format($this->amount).$withAddons.'. Paket saat ini tetap berlaku sampai '.$date.', lalu berganti ke '.$plan->name,
            default => 'Perpanjangan '.Money::format($this->amount).$withAddons.', periode mulai '.$date,
        };
    }

    /**
     * Add-on yang ikut perpanjangan: aktif (tidak sedang berhenti) dan fiturnya belum termasuk paket tujuan.
     *
     * @return list<TenantAddon>
     */
    public static function renewableAddons(Tenant $tenant, Plan $plan): array
    {
        return $tenant->activeAddons()
            ->filter(fn (TenantAddon $a): bool => $a->status === TenantAddon::ACTIVE
                && ($a->addon->isQuota() || ! in_array($a->addon->feature_key, $plan->features, true)))
            ->values()->all();
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

    /**
     * Harga satu siklus × sisa hari (dibulatkan ke atas) ÷ hari satu siklus, dibulatkan ke rupiah.
     *
     * @return array{0: string, 1: int} [nominal, sisa hari]
     */
    private static function prorate(string $cyclePrice, CarbonImmutable $ends, BillingCycle $cycle, CarbonImmutable $now): array
    {
        $cycleDays = max(1, (int) round($ends->subMonthsNoOverflow($cycle->months())->diffInDays($ends)));
        $remaining = max(1, (int) ceil($now->diffInDays($ends)));

        return [Money::of(Money::round(bcdiv(bcmul($cyclePrice, (string) $remaining, 6), (string) $cycleDays, 6), 0)), $remaining];
    }

    /** @return array{type: string, plan_id: string|null, addon_id: string|null, name: string, quantity: int, unit_price: string, amount: string} */
    private static function line(string $type, ?string $planId, ?string $addonId, string $name, int $quantity, string $unitPrice): array
    {
        return [
            'type' => $type, 'plan_id' => $planId, 'addon_id' => $addonId, 'name' => mb_substr($name, 0, 120),
            'quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => Money::mul($unitPrice, $quantity),
        ];
    }
}
