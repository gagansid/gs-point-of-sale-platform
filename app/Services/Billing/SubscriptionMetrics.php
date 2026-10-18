<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ringkasan pendapatan langganan SaaS untuk Dasbor panel /admin (ADR 0024). Lintas tenant: HANYA dipakai panel /admin.
 * Bulan dihitung di zona waktu pos.default_timezone; nominal string desimal (Money).
 */
final class SubscriptionMetrics
{
    private readonly string $timezone;

    public function __construct(private readonly ?CarbonImmutable $now = null)
    {
        $this->timezone = (string) config('pos.default_timezone');
    }

    /**
     * Total tagihan lunas per bulan (berdasarkan paid_at), terlama → terbaru, bulan berjalan terakhir.
     *
     * @return array<string, string> ['2026-10' => '1250000.00', ...]
     */
    public function paidPerMonth(int $months = 6): array
    {
        $current = $this->now()->setTimezone($this->timezone)->startOfMonth();
        $result = [];

        // Satu SUM per bulan dengan rentang UTC: portabel MySQL/MariaDB/SQLite tanpa fungsi zona waktu di SQL
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $current->subMonthsNoOverflow($i);
            $sum = $this->invoices()->where('status', InvoiceStatus::Paid)
                ->where('paid_at', '>=', $start->utc())
                ->where('paid_at', '<', $start->addMonthNoOverflow()->utc())
                ->sum('amount');
            $result[$start->format('Y-m')] = Money::fromDb($sum);
        }

        return $result;
    }

    /** @return array{count: int, amount: string} tagihan menunggu bayar (belum kedaluwarsa oleh jadwal harian) */
    public function pending(): array
    {
        $row = $this->invoices()->where('status', InvoiceStatus::Pending)
            ->selectRaw('COUNT(*) as aggregate_count, SUM(amount) as aggregate_amount')->toBase()->first();

        return ['count' => (int) ($row->aggregate_count ?? 0), 'amount' => Money::fromDb($row->aggregate_amount ?? null)];
    }

    /**
     * Langganan berbayar yang sedang berjalan per paket: status aktif, punya paket & siklus, masa aktif belum habis.
     * MRR = harga bulanan, atau harga tahunan ÷ 12, dari harga paket saat ini.
     *
     * @return array{mrr: string, count: int, plans: list<array{name: string, count: int}>}
     */
    public function activeSubscriptions(): array
    {
        $rows = Tenant::query()
            ->where('status', TenantStatus::Active)
            ->whereNotNull('plan_id')->whereNotNull('billing_cycle')
            ->where('subscription_ends_at', '>', $this->now())
            ->selectRaw('plan_id, billing_cycle, COUNT(*) as aggregate_count')
            ->groupBy('plan_id', 'billing_cycle')
            ->toBase()->get();

        $plans = Plan::query()->whereIn('id', $rows->pluck('plan_id')->unique())->orderBy('sort_order')->get()->keyBy('id');
        $mrr = '0.00';
        $perPlan = [];

        foreach ($rows as $row) {
            $plan = $plans->get($row->plan_id);
            if (! $plan instanceof Plan) {
                continue;
            }

            $count = (int) $row->aggregate_count;
            $mrr = Money::add($mrr, BillingCycle::from($row->billing_cycle) === BillingCycle::Yearly
                ? Money::div(Money::mul($plan->price_yearly, $count), 12)
                : Money::mul($plan->price_monthly, $count));
            $perPlan[$plan->id] = ($perPlan[$plan->id] ?? 0) + $count;
        }

        return [
            'mrr' => $mrr,
            'count' => array_sum($perPlan),
            'plans' => $plans->filter(fn (Plan $p): bool => isset($perPlan[$p->id]))
                ->map(fn (Plan $p): array => ['name' => $p->name, 'count' => $perPlan[$p->id]])->values()->all(),
        ];
    }

    public function trialCount(): int
    {
        return Tenant::query()->where('status', TenantStatus::Trial)->count();
    }

    /** @return Builder<SubscriptionInvoice> */
    private function invoices(): Builder
    {
        return SubscriptionInvoice::allTenants();
    }

    private function now(): CarbonImmutable
    {
        return $this->now ?? CarbonImmutable::now();
    }
}
