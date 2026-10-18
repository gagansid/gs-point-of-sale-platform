<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BusinessType;
use App\Enums\InvoiceStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\SalesLead;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Angka pertumbuhan platform untuk Dasbor /admin (lintas tenant; HANYA panel /admin). Bulan dihitung di
 * pos.default_timezone. Semua hasil di-cache lewat DashboardCache.
 */
final class PlatformMetrics
{
    /**
     * Bisnis per keadaan langganan (bisnis terhapus tidak dihitung).
     *
     * @return array{total: int, paid: int, trial: int, trial_ending: int, read_only: int, suspended: int}
     */
    public function tenantCounts(): array
    {
        return DashboardCache::remember('tenant-counts', function (): array {
            $now = CarbonImmutable::now();
            $active = fn () => Tenant::query()->where(fn ($q) => $q->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '>', $now));

            return [
                'total' => Tenant::query()->count(),
                'paid' => $active()->where('status', TenantStatus::Active)->whereNotNull('plan_id')->count(),
                'trial' => $active()->where('status', TenantStatus::Trial)->count(),
                'trial_ending' => Tenant::query()->where('status', TenantStatus::Trial)
                    ->whereBetween('subscription_ends_at', [$now, $now->addDays(7)])->count(),
                'read_only' => Tenant::query()->where('status', '!=', TenantStatus::Suspended)->where('subscription_ends_at', '<=', $now)->count(),
                'suspended' => Tenant::query()->where('status', TenantStatus::Suspended)->count(),
            ];
        });
    }

    /**
     * Bisnis baru per bulan (created_at), terlama → terbaru, bulan berjalan terakhir.
     *
     * @return array<string, int> ['2026-10' => 12, ...]
     */
    public function signupsPerMonth(int $months = 12): array
    {
        return DashboardCache::remember("signups-{$months}", function () use ($months): array {
            $current = CarbonImmutable::now(self::timezone())->startOfMonth();
            $result = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $start = $current->subMonthsNoOverflow($i);
                $result[$start->format('Y-m')] = Tenant::query()->withTrashed()
                    ->where('created_at', '>=', $start->utc())->where('created_at', '<', $start->addMonthNoOverflow()->utc())->count();
            }

            return $result;
        });
    }

    /** @return array{current: int, previous: int} bisnis baru $days hari terakhir vs $days hari sebelumnya */
    public function newTenants(int $days = 30): array
    {
        return DashboardCache::remember("new-tenants-{$days}", function () use ($days): array {
            $now = CarbonImmutable::now();

            return [
                'current' => Tenant::query()->withTrashed()->where('created_at', '>', $now->subDays($days))->count(),
                'previous' => Tenant::query()->withTrashed()->whereBetween('created_at', [$now->subDays($days * 2), $now->subDays($days)])->count(),
            ];
        });
    }

    /**
     * Konversi: bisnis yang dibuat $days hari terakhir dan sudah pernah membayar tagihan langganan.
     *
     * @return array{converted: int, cohort: int}
     */
    public function trialConversion(int $days = 90): array
    {
        return DashboardCache::remember("conversion-{$days}", function () use ($days): array {
            $cohort = Tenant::query()->where('created_at', '>', CarbonImmutable::now()->subDays($days));

            return [
                'cohort' => (clone $cohort)->count(),
                'converted' => (clone $cohort)->whereIn('id', SubscriptionInvoice::allTenants()->where('status', InvoiceStatus::Paid)->select('tenant_id'))->count(),
            ];
        });
    }

    /**
     * Bisnis (tidak ditangguhkan) per paket, terbanyak dulu; tanpa paket = "Tanpa paket".
     *
     * @return array<string, int> nama paket => jumlah
     */
    public function tenantsPerPlan(): array
    {
        return DashboardCache::remember('per-plan', function (): array {
            $counts = Tenant::query()->where('status', '!=', TenantStatus::Suspended)
                ->selectRaw('plan_id, COUNT(*) as aggregate_count')->groupBy('plan_id')->toBase()->get();
            $names = Plan::query()->whereIn('id', $counts->pluck('plan_id')->filter())->pluck('name', 'id');
            $result = [];
            foreach ($counts as $row) {
                $result[$row->plan_id === null ? 'Tanpa paket' : (string) ($names[$row->plan_id] ?? 'Paket terhapus')] = (int) $row->aggregate_count;
            }
            arsort($result);

            return $result;
        });
    }

    /**
     * Bisnis per jenis usaha, terbanyak dulu.
     *
     * @return array<string, int> label jenis => jumlah
     */
    public function tenantsPerBusinessType(): array
    {
        return DashboardCache::remember('per-business-type', function (): array {
            $result = [];
            foreach (Tenant::query()->selectRaw('business_type, COUNT(*) as aggregate_count')->groupBy('business_type')->toBase()->get() as $row) {
                $result[BusinessType::tryFrom((string) $row->business_type)?->getLabel() ?? (string) $row->business_type] = (int) $row->aggregate_count;
            }
            arsort($result);

            return $result;
        });
    }

    /** Calon pelanggan yang belum dihubungi. */
    public function newLeads(): int
    {
        return DashboardCache::remember('new-leads', fn (): int => SalesLead::query()->where('status', 'new')->count());
    }

    public static function timezone(): string
    {
        return (string) config('pos.default_timezone');
    }
}
