<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Concerns;

use App\Models\User;
use App\Services\Report\ReportFilters;
use App\Services\Report\ReportPeriod;
use App\Support\CurrentOutlet;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\On;

/**
 * Bersama untuk widget beranda: hanya untuk yang punya report.view, angka mengikuti filter
 * halaman Beranda ($pageFilters, reaktif: widget dirender ulang setiap filter berubah).
 * Tanpa filter (mis. test widget langsung) = hari ini, dibanding periode sebelumnya.
 */
trait ReportWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('report.view');
    }

    /** Tombol "Muat ulang" di Beranda: hitung ulang tanpa mengubah filter. */
    #[On('dashboard-refresh')]
    public function refreshReport(): void
    {
        // Cukup memicu render ulang
    }

    protected function period(): ReportPeriod
    {
        $filters = $this->pageFilters ?? [];
        $today = CurrentOutlet::today();
        $preset = (string) ($filters['preset'] ?? 'today');

        return $preset === 'custom'
            ? ReportPeriod::normalize($filters['from'] ?? null, $filters['to'] ?? null, $today)
            : ReportPeriod::preset($preset, $today);
    }

    protected function comparisonPeriod(): ?ReportPeriod
    {
        return $this->period()->comparison((string) ($this->pageFilters['compare'] ?? 'previous_period'));
    }

    protected function reportFilters(): ReportFilters
    {
        return ReportFilters::fromArray($this->pageFilters ?? []);
    }
}
