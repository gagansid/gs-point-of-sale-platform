<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * Produk terlaris (top 10, % kontribusi omzet) atau paling tidak laku (termasuk yang tidak
 * terjual sama sekali) pada periode terpilih.
 */
final class TopProductsWidget extends Widget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.dashboard.widgets.top-products';

    /** "top" = terlaris, "slow" = paling tidak laku */
    public string $mode = 'top';

    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'slow' ? 'slow' : 'top';
    }

    /** @return list<array{product_id: string, product_name: string, qty: int, revenue: string, share: string}> */
    public function getProducts(): array
    {
        $reports = app(ReportService::class);
        [$start, $end] = $this->period()->utcRange();
        $filters = $this->reportFilters();

        $rows = $this->mode === 'slow'
            ? $reports->slowProducts($start, $end, 10, $filters)
            : $reports->products($start, $end, 10, $filters);

        $itemsTotal = Money::add('0', ...array_column($reports->products($start, $end, null, $filters), 'revenue'));

        // Kontribusi terhadap total nilai item (sebelum pajak/service), 0 bila belum ada penjualan
        return array_map(fn (array $r): array => [
            ...$r,
            'share' => Money::compare($itemsTotal, '0') > 0 ? Money::round(bcdiv(bcmul($r['revenue'], '100', 4), $itemsTotal, 4), 1) : '0.0',
        ], $rows);
    }
}
