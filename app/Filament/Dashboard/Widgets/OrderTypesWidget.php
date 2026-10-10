<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * Makan di tempat vs bawa pulang: porsi omzet & transaksi (bantu atur meja dan kemasan).
 */
final class OrderTypesWidget extends Widget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    protected string $view = 'filament.dashboard.widgets.order-types';

    /** @return list<array{order_type: string, label: string, order_count: int, revenue: string, average: string, share: string}> */
    public function getTypes(): array
    {
        [$start, $end] = $this->period()->utcRange();
        $rows = app(ReportService::class)->orderTypes($start, $end, $this->reportFilters());
        $total = Money::add('0', ...array_column($rows, 'revenue'));

        return array_map(fn (array $r): array => [
            ...$r,
            'average' => Money::div($r['revenue'], $r['order_count']),
            'share' => Money::compare($total, '0') > 0 ? Money::round(bcdiv(bcmul($r['revenue'], '100', 4), $total, 4), 0) : '0',
        ], $rows);
    }
}
