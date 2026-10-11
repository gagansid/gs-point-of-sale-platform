<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Concerns;

use App\Enums\OrderType;
use App\Services\Report\ReportPeriod;
use Livewire\Attributes\Url;

/**
 * Query string ringkas untuk halaman laporan berfilter: hanya nilai yang bukan default.
 *
 *   /dashboard                                   → hari ini (atau filter terakhir dari session)
 *   /dashboard?period=last_30_days
 *   /dashboard?period=custom&from=2026-10-01&to=2026-10-10&compare=previous_year&type=dine_in
 *   /dashboard/reports?tab=products&period=this_year&group=month
 *
 * State lengkap tetap di $filters (session); outlet mengikuti pemilih sidebar, metode bayar &
 * kasir hanya di session agar URL tidak berisi deretan UUID.
 */
trait SyncsReportFiltersToUrl
{
    #[Url(as: 'period', except: 'today')]
    public string $urlPeriod = 'today';

    #[Url(as: 'from', except: '')]
    public string $urlFrom = '';

    #[Url(as: 'to', except: '')]
    public string $urlTo = '';

    #[Url(as: 'compare', except: 'previous_period')]
    public string $urlCompare = 'previous_period';

    #[Url(as: 'type', except: '')]
    public string $urlType = '';

    /** Granularitas tren (halaman Laporan); kosong = otomatis sesuai rentang */
    #[Url(as: 'group', except: '')]
    public string $urlGroup = '';

    /** Ada parameter laporan di URL (link dibagikan/bookmark) → URL menang atas session. */
    protected function hasReportQuery(): bool
    {
        return array_intersect(['period', 'from', 'to', 'compare', 'type', 'group'], array_keys(request()->query())) !== [];
    }

    /**
     * Nilai dari URL ditimpakan ke filter (belum dinormalisasi).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function filtersWithUrl(array $filters): array
    {
        return [
            ...$filters,
            'preset' => $this->urlPeriod,
            'from' => $this->urlFrom !== '' ? $this->urlFrom : null,
            'to' => $this->urlTo !== '' ? $this->urlTo : null,
            'compare' => $this->urlCompare,
            'order_type' => $this->urlType !== '' ? $this->urlType : null,
            'granularity' => $this->urlGroup !== '' ? $this->urlGroup : ($filters['granularity'] ?? null),
        ];
    }

    /** @param  array<string, mixed>  $filters */
    protected function syncUrlFromFilters(array $filters): void
    {
        $preset = is_string($filters['preset'] ?? null) && isset(ReportPeriod::PRESETS[$filters['preset']]) ? $filters['preset'] : 'today';
        $date = fn (mixed $value): string => is_string($value) ? substr($value, 0, 10) : '';
        $type = $filters['order_type'] ?? null;

        $this->urlPeriod = $preset;
        // Tanggal hanya perlu untuk kustom; preset lain dihitung ulang dari hari ini
        $this->urlFrom = $preset === 'custom' ? $date($filters['from'] ?? null) : '';
        $this->urlTo = $preset === 'custom' ? $date($filters['to'] ?? null) : '';
        $this->urlCompare = is_string($filters['compare'] ?? null) && isset(ReportPeriod::COMPARES[$filters['compare']]) ? $filters['compare'] : 'previous_period';
        $this->urlType = is_string($type) && OrderType::tryFrom($type) !== null ? $type : '';
        $group = $filters['granularity'] ?? null;
        $this->urlGroup = is_string($group) && isset(ReportPeriod::GRANULARITIES[$group]) && $group !== ($filters['granularity_default'] ?? null) ? $group : '';
    }
}
