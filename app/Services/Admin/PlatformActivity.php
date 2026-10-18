<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AppPlatform;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\AppVersion;
use App\Models\Device;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aktivitas platform lintas tenant untuk Dasbor /admin (D3): transaksi & nilai transaksi (GMV = total order selesai,
 * sebelum retur), perangkat aktif, versi aplikasi, bisnis paling aktif & berisiko berhenti. HANYA panel /admin.
 * Agregat di SQL (bukan iterasi order) agar ringan untuk banyak tenant; hasil di-cache lewat DashboardCache.
 */
final class PlatformActivity
{
    /**
     * Transaksi & nilai per hari lokal (pos.default_timezone), terlama → terbaru; hari kosong = 0.
     *
     * @return array<string, array{count: int, gmv: string}> ['2026-10-18' => [...]]
     */
    public function perDay(int $days = 30): array
    {
        return DashboardCache::remember("activity-per-day-{$days}", function () use ($days): array {
            $tz = PlatformMetrics::timezone();
            $today = CarbonImmutable::now($tz)->startOfDay();
            $start = $today->subDays($days - 1);
            $result = [];
            for ($d = $start; $d->lte($today); $d = $d->addDay()) {
                $result[$d->toDateString()] = ['count' => 0, 'gmv' => '0.00'];
            }

            $day = self::localDateSql('completed_at', $start->getOffset());
            $rows = self::completed()->where('completed_at', '>=', $start->utc())
                ->selectRaw("{$day} as local_day, COUNT(*) as aggregate_count, SUM(grand_total) as aggregate_total")
                ->groupBy('local_day')->toBase()->get();

            foreach ($rows as $row) {
                if (isset($result[(string) $row->local_day])) {
                    $result[(string) $row->local_day] = ['count' => (int) $row->aggregate_count, 'gmv' => Money::fromDb($row->aggregate_total)];
                }
            }

            return $result;
        });
    }

    /**
     * Ringkasan $days hari terakhir vs $days hari sebelumnya.
     *
     * @return array{count: int, gmv: string, tenants: int, previous_count: int, previous_gmv: string}
     */
    public function totals(int $days = 30): array
    {
        return DashboardCache::remember("activity-totals-{$days}", function () use ($days): array {
            $now = CarbonImmutable::now();
            $sum = fn (CarbonImmutable $from, CarbonImmutable $to) => self::completed()->whereBetween('completed_at', [$from, $to])
                ->selectRaw('COUNT(*) as aggregate_count, SUM(grand_total) as aggregate_total, COUNT(DISTINCT tenant_id) as aggregate_tenants')
                ->toBase()->first();
            $current = $sum($now->subDays($days), $now);
            $previous = $sum($now->subDays($days * 2), $now->subDays($days));

            return [
                'count' => (int) ($current->aggregate_count ?? 0),
                'gmv' => Money::fromDb($current->aggregate_total ?? null),
                'tenants' => (int) ($current->aggregate_tenants ?? 0),
                'previous_count' => (int) ($previous->aggregate_count ?? 0),
                'previous_gmv' => Money::fromDb($previous->aggregate_total ?? null),
            ];
        });
    }

    /** @return array{active: int, total: int, android: int, web: int} perangkat tidak dicabut; aktif = terlihat 7 hari */
    public function devices(): array
    {
        return DashboardCache::remember('devices', function (): array {
            $base = fn () => Device::allTenants()->whereNull('revoked_at');
            $active = fn () => $base()->where('last_seen_at', '>=', now()->subDays(7));

            return [
                'active' => $active()->count(),
                'total' => $base()->count(),
                'android' => $active()->where('platform', AppPlatform::Android)->count(),
                'web' => $active()->where('platform', AppPlatform::Web)->count(),
            ];
        });
    }

    /**
     * Versi aplikasi Android pada perangkat aktif 30 hari, terbaru dulu, beserta batas minimum.
     *
     * @return array{versions: array<string, int>, min: string|null, below_min: int}
     */
    public function androidVersions(): array
    {
        return DashboardCache::remember('android-versions', function (): array {
            $rows = Device::allTenants()->whereNull('revoked_at')->where('platform', AppPlatform::Android)
                ->where('last_seen_at', '>=', now()->subDays(30))
                ->selectRaw("COALESCE(app_version, '?') as version, COUNT(*) as aggregate_count")
                ->groupBy('version')->toBase()->get();
            $versions = $rows->mapWithKeys(fn (object $r): array => [(string) $r->version => (int) $r->aggregate_count])->all();
            uksort($versions, fn (string $a, string $b): int => version_compare($b, $a));
            $min = AppVersion::forPlatform(AppPlatform::Android)?->min_version;

            return [
                'versions' => $versions,
                'min' => $min,
                'below_min' => $min === null ? 0 : (int) array_sum(array_filter($versions, fn (int $n, string $v): bool => $v === '?' || version_compare($v, $min, '<'), ARRAY_FILTER_USE_BOTH)),
            ];
        });
    }

    /**
     * Bisnis dengan transaksi terbanyak $days hari terakhir.
     *
     * @return list<array{tenant_id: string, name: string, count: int, gmv: string}>
     */
    public function topTenants(int $days = 30, int $limit = 6): array
    {
        return DashboardCache::remember("top-tenants-{$days}-{$limit}", function () use ($days, $limit): array {
            $rows = self::completed()->where('completed_at', '>=', now()->subDays($days))
                ->selectRaw('tenant_id, COUNT(*) as aggregate_count, SUM(grand_total) as aggregate_total')
                ->groupBy('tenant_id')->orderByDesc('aggregate_count')->limit($limit)->toBase()->get();
            $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id'))->pluck('name', 'id');

            return $rows->map(fn (object $r): array => [
                'tenant_id' => (string) $r->tenant_id,
                'name' => (string) ($names[$r->tenant_id] ?? '—'),
                'count' => (int) $r->aggregate_count,
                'gmv' => Money::fromDb($r->aggregate_total),
            ])->values()->all();
        });
    }

    /**
     * Bisnis aktif (trial / berlangganan, masa aktif berjalan, berumur ≥ $idleDays hari) tanpa transaksi $idleDays hari
     * terakhir: belum pernah transaksi dulu, lalu yang paling lama diam. Kandidat dihubungi (onboarding / risiko berhenti).
     * Isi cache hanya nilai skalar (aman di-unserialize dari cache database).
     *
     * @return array{count: int, rows: list<array{tenant_id: string, name: string, status: string, plan: string|null, last_order_at: string|null}>}
     */
    public function atRiskTenants(int $idleDays = 14, int $limit = 6): array
    {
        return DashboardCache::remember("at-risk-{$idleDays}-{$limit}", function () use ($idleDays, $limit): array {
            $since = CarbonImmutable::now()->subDays($idleDays);
            $query = Tenant::query()
                ->where('status', '!=', TenantStatus::Suspended)
                ->where(fn (Builder $q) => $q->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '>', now()))
                // Bisnis yang baru daftar diberi waktu untuk mulai berjualan
                ->where('created_at', '<=', $since)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')->whereColumn('orders.tenant_id', 'tenants.id')
                    ->where('orders.status', OrderStatus::Completed->value)->where('orders.completed_at', '>=', $since));

            $rows = (clone $query)->with('plan:id,name')
                ->addSelect(['last_order_at' => Order::allTenants()->whereColumn('orders.tenant_id', 'tenants.id')
                    ->where('status', OrderStatus::Completed)->selectRaw('MAX(completed_at)')])
                ->orderByRaw('last_order_at IS NOT NULL')->orderBy('last_order_at')->limit($limit)->get();

            return [
                'count' => $query->count(),
                'rows' => $rows->map(fn (Tenant $t): array => [
                    'tenant_id' => $t->id,
                    'name' => $t->name,
                    'status' => $t->status->value,
                    'plan' => $t->plan?->name,
                    'last_order_at' => $t->getAttribute('last_order_at') !== null ? CarbonImmutable::parse((string) $t->getAttribute('last_order_at'), 'UTC')->toIso8601String() : null,
                ])->all(),
            ];
        });
    }

    /** @return Builder<Order> order selesai semua tenant */
    private static function completed(): Builder
    {
        return Order::allTenants()->where('status', OrderStatus::Completed)->whereNotNull('completed_at');
    }

    /** Tanggal lokal dari kolom UTC dengan offset tetap (zona Indonesia tanpa DST), MySQL/MariaDB & SQLite. */
    private static function localDateSql(string $column, int $offsetSeconds): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("date(%s, '%+d seconds')", $column, $offsetSeconds)
            : sprintf('DATE(DATE_ADD(%s, INTERVAL %d SECOND))', $column, $offsetSeconds);
    }
}
