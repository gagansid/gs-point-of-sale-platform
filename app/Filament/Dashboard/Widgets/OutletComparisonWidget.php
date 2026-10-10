<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * Perbandingan outlet: hanya saat "Semua outlet" dipilih dan user memegang > 1 outlet.
 */
final class OutletComparisonWidget extends Widget
{
    use ReportWidget {
        canView as private canViewReports;
    }

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.widgets.outlet-comparison';

    public static function canView(): bool
    {
        $user = auth()->user();

        return self::canViewReports() && $user instanceof User && count($user->outletIds()) > 1;
    }

    /** Disembunyikan (bukan dihapus) saat satu outlet dipilih: widget tetap ada untuk reaktivitas filter. */
    public function isVisible(): bool
    {
        return CurrentOutlet::selectedId() === null;
    }

    /** @return list<array{outlet_id: string, name: string, order_count: int, revenue: string, average: string, void_count: int, void_total: string, share: string, change: string|null}> */
    public function getOutlets(): array
    {
        $reports = app(ReportService::class);
        $filters = $this->reportFilters();
        [$start, $end] = $this->period()->utcRange();

        $rows = $reports->outlets($start, $end, $filters);
        $comparison = $this->comparisonPeriod();
        $before = $comparison !== null ? collect($reports->outlets(...[...$comparison->utcRange(), $filters]))->keyBy('outlet_id') : null;
        $total = Money::add('0', ...array_column($rows, 'revenue'));

        return array_map(fn (array $r): array => [
            ...$r,
            'share' => Money::compare($total, '0') > 0 ? Money::round(bcdiv(bcmul($r['revenue'], '100', 4), $total, 4), 1) : '0.0',
            'change' => $before !== null ? ReportService::change($r['revenue'], (string) ($before[$r['outlet_id']]['revenue'] ?? '0.00')) : null,
        ], $rows);
    }
}
