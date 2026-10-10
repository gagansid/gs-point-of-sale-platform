<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use Filament\Widgets\Widget;

/**
 * Panel audit: void (pelaku & approver), diskon dengan approval PIN, dan shift bermasalah
 * (selisih kas atau ditutup paksa) pada periode terpilih. Angka dari ReportService::audit.
 */
final class AuditWidget extends Widget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.widgets.audit';

    /** @return array<string, mixed> */
    public function getAudit(): array
    {
        [$start, $end] = $this->period()->utcRange();

        return app(ReportService::class)->audit($start, $end, 5, $this->reportFilters());
    }
}
