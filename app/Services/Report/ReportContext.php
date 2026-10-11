<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\User;
use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;

/**
 * Satu paket parameter laporan dari input bebas (filter Beranda/Laporan, query export):
 * periode, pembanding, granularitas tren, dan filter tambahan.
 *
 * Batas supervisor (Q49): user tanpa report.view hanya boleh melihat SUPERVISOR_LOOKBACK_DAYS
 * terakhir; rentang di luar itu DIPOTONG di sini (web) — API menolak dengan 422 di ReportRequest.
 */
final readonly class ReportContext
{
    public function __construct(
        public ReportPeriod $period,
        public ?ReportPeriod $comparison,
        public string $compare,
        public string $granularity,
        public ReportFilters $filters,
        public ?string $minDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  kunci: preset, from, to, compare, granularity + filter ReportFilters
     */
    public static function fromInput(array $input, ?User $user = null): self
    {
        $today = CurrentOutlet::today();
        $minDate = self::minDateFor($user, $today);
        $preset = is_string($input['preset'] ?? null) && isset(ReportPeriod::PRESETS[$input['preset']]) ? $input['preset'] : 'today';

        $period = $preset === 'custom'
            ? ReportPeriod::normalize($input['from'] ?? null, $input['to'] ?? null, $today, $minDate)
            : ReportPeriod::preset($preset, $today)->clampTo($minDate);

        $compare = is_string($input['compare'] ?? null) && isset(ReportPeriod::COMPARES[$input['compare']]) ? $input['compare'] : 'previous_period';
        // Pembanding supervisor juga tidak boleh keluar dari batas lihat ke belakang
        $comparison = $period->comparison($compare);
        if ($comparison !== null && $minDate !== null && $comparison->from < $minDate) {
            $comparison = null;
        }

        $granularity = $period->granularity(is_string($input['granularity'] ?? null) ? $input['granularity'] : null);

        return new self($period, $comparison, $comparison !== null ? $compare : 'none', $granularity, ReportFilters::fromArray($input), $minDate);
    }

    /** Batas tanggal paling awal untuk user; null = tanpa batas (report.view). */
    public static function minDateFor(?User $user, ?string $today = null): ?string
    {
        if ($user === null || $user->can('report.view')) {
            return null;
        }

        return ReportPeriod::lookbackStart($today ?? CurrentOutlet::today());
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        return $this->period->utcRange();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public function comparisonRange(): ?array
    {
        return $this->comparison?->utcRange();
    }

    /** "periode sebelumnya" / "tahun lalu" untuk teks tren; null tanpa pembanding. */
    public function versusLabel(): ?string
    {
        return match (true) {
            $this->comparison === null => null,
            $this->compare === 'previous_year' => 'tahun lalu',
            default => 'periode sebelumnya',
        };
    }
}
