<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Concerns;

use App\Models\User;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportFilters;
use App\Services\Report\ReportPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\On;

/**
 * Bersama untuk widget laporan (Beranda & halaman Laporan): angka mengikuti filter halaman
 * ($pageFilters, reaktif: widget dirender ulang setiap filter berubah) lewat ReportContext,
 * termasuk batas 31 hari supervisor (Q49). Tanpa filter = hari ini, dibanding periode sebelumnya.
 *
 * Izin: bawaan report.view; widget operasional menimpa reportPermissions() (mis. + report.view_shift).
 */
trait ReportWidget
{
    use InteractsWithPageFilters;

    private ?ReportContext $reportContext = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        foreach (static::reportPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> salah satu cukup */
    protected static function reportPermissions(): array
    {
        return ['report.view'];
    }

    /** Tombol "Muat ulang": hitung ulang tanpa mengubah filter. */
    #[On('dashboard-refresh')]
    public function refreshReport(): void
    {
        // Cukup memicu render ulang
    }

    protected function context(): ReportContext
    {
        $user = auth()->user();

        // Dihitung sekali per request (pageFilters baru = request baru)
        return $this->reportContext ??= ReportContext::fromInput($this->pageFilters ?? [], $user instanceof User ? $user : null);
    }

    protected function period(): ReportPeriod
    {
        return $this->context()->period;
    }

    protected function comparisonPeriod(): ?ReportPeriod
    {
        return $this->context()->comparison;
    }

    protected function reportFilters(): ReportFilters
    {
        return $this->context()->filters;
    }
}
