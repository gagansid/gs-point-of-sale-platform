<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use Filament\Widgets\Widget;

/**
 * Laporan › Ringkasan: rincian finansial ala laporan laba kotor — bruto, diskon, service, pajak,
 * pembulatan, omzet diterima, penjualan bersih (tanpa pajak & service), void.
 */
final class FinancialBreakdownWidget extends Widget
{
    use ReportWidget;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.widgets.reports.financial-breakdown';

    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        [$start, $end] = $this->context()->range();

        return app(ReportService::class)->summary($start, $end, $this->context()->filters);
    }
}
