<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rentang tanggal LOKAL outlet (inklusif) untuk laporan + preset & periode pembanding.
 * Dipakai beranda dashboard dan API /reports/dashboard agar aturan periodenya sama.
 */
final readonly class ReportPeriod
{
    public const MAX_DAYS = 366;

    /** Batas lihat ke belakang untuk report.view_shift tanpa report.view (Q49), termasuk hari ini */
    public const SUPERVISOR_LOOKBACK_DAYS = 31;

    /** @var array<string, string> */
    public const PRESETS = [
        'today' => 'Hari ini',
        'yesterday' => 'Kemarin',
        'this_week' => 'Minggu ini',
        'last_week' => 'Minggu lalu',
        'last_7_days' => '7 hari terakhir',
        'last_30_days' => '30 hari terakhir',
        'this_month' => 'Bulan ini',
        'last_month' => 'Bulan lalu',
        'this_year' => 'Tahun ini',
        'last_year' => 'Tahun lalu',
        'custom' => 'Kustom',
    ];

    /** @var array<string, string> */
    public const GRANULARITIES = [
        'hour' => 'Per jam',
        'day' => 'Per hari',
        'week' => 'Per minggu',
        'month' => 'Per bulan',
    ];

    /** @var array<string, string> */
    public const COMPARES = [
        'previous_period' => 'Periode sebelumnya',
        'previous_year' => 'Periode sama tahun lalu',
        'none' => 'Tanpa pembanding',
    ];

    public function __construct(public string $from, public string $to) {}

    /** Preset → rentang; preset tidak dikenal / "custom" = hari ini. */
    public static function preset(string $preset, string $today): self
    {
        $day = CarbonImmutable::parse($today);

        return match ($preset) {
            'yesterday' => new self($day->subDay()->toDateString(), $day->subDay()->toDateString()),
            'last_7_days' => new self($day->subDays(6)->toDateString(), $today),
            'last_30_days' => new self($day->subDays(29)->toDateString(), $today),
            // Minggu ISO: Senin–Minggu
            'this_week' => new self($day->startOfWeek(CarbonInterface::MONDAY)->toDateString(), $today),
            'last_week' => new self($day->subWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString(), $day->subWeek()->endOfWeek(CarbonInterface::SUNDAY)->toDateString()),
            'this_month' => new self($day->startOfMonth()->toDateString(), $today),
            'last_month' => new self($day->subMonthNoOverflow()->startOfMonth()->toDateString(), $day->subMonthNoOverflow()->endOfMonth()->toDateString()),
            'this_year' => new self($day->startOfYear()->toDateString(), $today),
            'last_year' => new self($day->subYear()->startOfYear()->toDateString(), $day->subYear()->endOfYear()->toDateString()),
            default => new self($today, $today),
        };
    }

    /**
     * Input bebas (form dashboard) → rentang aman: urutan dibetulkan, tidak melewati hari ini,
     * maksimal MAX_DAYS hari, tidak sebelum $minDate (batas supervisor). Tanggal tidak valid = hari ini.
     */
    public static function normalize(mixed $from, mixed $to, string $today, ?string $minDate = null): self
    {
        $parse = function (mixed $value) use ($today): CarbonImmutable {
            // Hanya "Y-m-d" (boleh diikuti jam dari DatePicker); selain itu = hari ini, tanpa exception
            $day = is_string($value) ? substr(trim($value), 0, 10) : '';

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $day) ?: CarbonImmutable::parse($today);
            }

            return CarbonImmutable::parse($today);
        };

        $max = CarbonImmutable::parse($today);
        $start = $parse($from)->min($max);
        $end = $parse($to)->min($max);

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        if ($minDate !== null) {
            $min = CarbonImmutable::parse($minDate);
            $start = $start->max($min);
            $end = $end->max($min);
        }

        return new self($start->toDateString(), $end->toDateString());
    }

    /** Tanggal paling awal yang boleh dilihat supervisor (report.view_shift), Q49. */
    public static function lookbackStart(string $today): string
    {
        return CarbonImmutable::parse($today)->subDays(self::SUPERVISOR_LOOKBACK_DAYS - 1)->toDateString();
    }

    /** Membatasi rentang agar tidak sebelum $minDate (null = tanpa batas). */
    public function clampTo(?string $minDate): self
    {
        if ($minDate === null || $this->from >= $minDate) {
            return $this;
        }

        return new self($minDate, max($this->to, $minDate));
    }

    /**
     * Granularitas tren bawaan: ≤ 1 hari per jam, ≤ 62 hari per hari, ≤ 180 hari per minggu, sisanya per bulan.
     */
    public function defaultGranularity(): string
    {
        $days = $this->days();

        return match (true) {
            $days <= 1 => 'hour',
            $days <= 62 => 'day',
            $days <= 180 => 'week',
            default => 'month',
        };
    }

    /**
     * Granularitas yang masuk akal untuk rentang ini (titik grafik tidak terlalu sedikit/banyak).
     *
     * @return list<string>
     */
    public function allowedGranularities(): array
    {
        $days = $this->days();

        return match (true) {
            $days <= 1 => ['hour'],
            $days <= 13 => ['day'],
            $days <= 62 => ['day', 'week'],
            $days <= 180 => ['day', 'week', 'month'],
            default => ['week', 'month'],
        };
    }

    /** Granularitas pilihan bila diizinkan, selain itu bawaan. */
    public function granularity(?string $requested): string
    {
        return $requested !== null && in_array($requested, $this->allowedGranularities(), true) ? $requested : $this->defaultGranularity();
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    public function isSingleDay(): bool
    {
        return $this->from === $this->to;
    }

    /** Periode pembanding; null bila "none" / tidak dikenal. */
    public function comparison(string $compare): ?self
    {
        $from = CarbonImmutable::parse($this->from);
        $to = CarbonImmutable::parse($this->to);

        return match ($compare) {
            'previous_period' => new self($from->subDays($this->days())->toDateString(), $from->subDay()->toDateString()),
            'previous_year' => new self($from->subYearNoOverflow()->toDateString(), $to->subYearNoOverflow()->toDateString()),
            default => null,
        };
    }

    /**
     * Rentang UTC untuk query (zona waktu outlet aktif).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcRange(): array
    {
        return CurrentOutlet::utcRange($this->from, $this->to);
    }

    /** Label Indonesia, mis. "1 – 31 Okt 2026" atau "10 Okt 2026". */
    public function label(): string
    {
        $from = CarbonImmutable::parse($this->from)->locale('id');
        $to = CarbonImmutable::parse($this->to)->locale('id');

        if ($this->isSingleDay()) {
            return $from->translatedFormat('j M Y');
        }

        $format = $from->year === $to->year ? ($from->month === $to->month ? 'j' : 'j M') : 'j M Y';

        return $from->translatedFormat($format).' – '.$to->translatedFormat('j M Y');
    }
}
