<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use Filament\Widgets\Widget;

/**
 * Produk terlaris 7 hari terakhir (top 5).
 */
final class TopProductsWidget extends Widget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected string $view = 'filament.dashboard.widgets.top-products';

    /** @return list<array{product_id: string, product_name: string, qty: int, revenue: string}> */
    public function getProducts(): array
    {
        return app(ReportService::class)->products(...[...$this->lastDays(7), 5]);
    }
}
