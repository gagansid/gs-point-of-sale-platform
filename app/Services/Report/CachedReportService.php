<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache agregat laporan (R2): Beranda, Laporan, API, & export lebih ringan di shared hosting.
 * Di-bind sebagai ReportService di container, jadi semua pemanggil memakai cache tanpa perubahan.
 *
 * Kunci = tenant + outlet yang terlihat user + nama method + argumen (tanggal UTC, filter). TTL
 * `pos.reports.cache_seconds` (bawaan 60 detik); periode yang sudah selesai (berakhir > 1 jam lalu)
 * di-cache 10× lebih lama karena angkanya tidak berubah lagi (kecuali void susulan). 0 = nonaktif.
 * Query builder (voidQuery dst.) tidak di-cache.
 */
final class CachedReportService extends ReportService
{
    public function summary(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::summary($start, $end, $filters));
    }

    public function products(CarbonImmutable $start, CarbonImmutable $end, ?int $limit = null, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $limit, $filters], fn (): array => parent::products($start, $end, $limit, $filters));
    }

    public function slowProducts(CarbonImmutable $start, CarbonImmutable $end, int $limit = 10, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $limit, $filters], fn (): array => parent::slowProducts($start, $end, $limit, $filters));
    }

    public function paymentMethods(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::paymentMethods($start, $end, $filters));
    }

    public function daily(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $timezone, $filters], fn (): array => parent::daily($start, $end, $timezone, $filters));
    }

    public function dailyBreakdown(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $timezone, $filters], fn (): array => parent::dailyBreakdown($start, $end, $timezone, $filters));
    }

    public function hourly(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $timezone, $filters], fn (): array => parent::hourly($start, $end, $timezone, $filters));
    }

    public function orderTypes(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::orderTypes($start, $end, $filters));
    }

    public function outlets(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::outlets($start, $end, $filters));
    }

    public function audit(CarbonImmutable $start, CarbonImmutable $end, int $limit = 10, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $limit, $filters], fn (): array => parent::audit($start, $end, $limit, $filters));
    }

    public function trend(CarbonImmutable $start, CarbonImmutable $end, string $timezone, string $granularity, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $timezone, $granularity, $filters], fn (): array => parent::trend($start, $end, $timezone, $granularity, $filters));
    }

    public function categories(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::categories($start, $end, $filters));
    }

    public function profit(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::profit($start, $end, $filters));
    }

    public function options(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::options($start, $end, $filters));
    }

    public function employees(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::employees($start, $end, $filters));
    }

    public function shifts(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null, ?string $openedBy = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters, $openedBy], fn (): array => parent::shifts($start, $end, $filters, $openedBy));
    }

    public function heatmap(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $timezone, $filters], fn (): array => parent::heatmap($start, $end, $timezone, $filters));
    }

    public function stock(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, [$start, $end, $filters], fn (): array => parent::stock($start, $end, $filters));
    }

    /**
     * Buang cache laporan satu tenant (tombol "Muat ulang"): versi naik → semua kunci lama tidak terpakai
     * dan kedaluwarsa sendiri (driver database tidak mendukung hapus per prefix).
     */
    public static function flush(string $tenantId): void
    {
        Cache::forever(self::versionKey($tenantId), self::version($tenantId) + 1);
    }

    private static function version(string $tenantId): int
    {
        return (int) Cache::get(self::versionKey($tenantId), 0);
    }

    private static function versionKey(string $tenantId): string
    {
        return 'report-version:'.$tenantId;
    }

    /**
     * @param  list<mixed>  $args
     * @param  Closure(): array<mixed>  $compute
     * @return array<mixed>
     */
    private function remember(string $method, array $args, Closure $compute): array
    {
        $ttl = (int) config('pos.reports.cache_seconds');
        $tenantId = TenantContext::id();

        if ($ttl <= 0 || $tenantId === null) {
            return $compute();
        }

        $normalized = array_map(fn (mixed $arg): mixed => match (true) {
            $arg instanceof CarbonImmutable => $arg->utc()->toIso8601String(),
            $arg instanceof ReportFilters => $arg->toArray(),
            default => $arg,
        }, $args);

        $ended = collect($args)->filter(fn (mixed $a): bool => $a instanceof CarbonImmutable)->last();
        $settled = $ended instanceof CarbonImmutable && $ended->lt(now()->subHour());

        $key = 'report:'.$tenantId.':v'.self::version($tenantId).':'.$method.':'.md5((string) json_encode([CurrentOutlet::ids(), CurrentOutlet::selectedId(), $normalized]));

        return Cache::remember($key, $settled ? $ttl * 10 : $ttl, $compute);
    }
}
