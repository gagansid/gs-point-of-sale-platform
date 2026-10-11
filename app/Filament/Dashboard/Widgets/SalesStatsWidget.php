<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Models\Product;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Beranda: KPI periode terpilih dibanding periode pembanding (stat-widget.md).
 * Naik = hijau untuk omzet/transaksi; untuk diskon & void kenaikan justru diberi warna peringatan.
 */
final class SalesStatsWidget extends StatsOverviewWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    // Shared hosting: tanpa polling otomatis (stat-widget.md)
    protected ?string $pollingInterval = null;

    /** @return array<string, int> 1 kolom (mobile) → 2 (md) → 4 (xl), stat-widget.md */
    protected function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $reports = app(ReportService::class);
        $period = $this->period();
        $filters = $this->reportFilters();
        [$start, $end] = $period->utcRange();

        $now = $reports->summary($start, $end, $filters);
        $comparison = $this->comparisonPeriod();
        $before = $comparison !== null ? $reports->summary(...[...$comparison->utcRange(), $filters]) : null;
        $versus = match (true) {
            $comparison === null => null,
            ($this->pageFilters['compare'] ?? 'previous_period') === 'previous_year' => 'tahun lalu ('.$comparison->label().')',
            default => 'periode sebelumnya ('.$comparison->label().')',
        };

        // Sparkline omzet: per jam untuk 1 hari, per hari untuk rentang
        $spark = $period->isSingleDay()
            ? $reports->hourly($start, $end, CurrentOutlet::timezone(), $filters)
            : $reports->daily($start, $end, CurrentOutlet::timezone(), $filters);

        $outlet = CurrentOutlet::selectedId() !== null ? CurrentOutlet::get() : null;
        $outOfStock = $outlet !== null
            ? Product::query()->active()->outOfStock($outlet->id)->count()
            : collect(CurrentOutlet::ids())->sum(fn (string $id): int => Product::query()->active()->outOfStock($id)->count());

        $count = fn (int $n): string => number_format($n, 0, ',', '.');

        return [
            $this->trend(Stat::make('Omzet', Money::format($now['revenue'])), $now['revenue'], $before['revenue'] ?? null, $versus)
                ->icon(Heroicon::OutlinedBanknotes)
                ->chart(array_map(fn (array $r): float => (float) $r['revenue'], $spark)),
            $this->trend(Stat::make('Transaksi', $count($now['order_count'])), (string) $now['order_count'], isset($before) ? (string) $before['order_count'] : null, $versus)
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->url(OrderResource::getUrl('index')),
            $this->trend(Stat::make('Rata-rata transaksi', Money::format($now['average'])), $now['average'], $before['average'] ?? null, $versus)
                ->icon(Heroicon::OutlinedCalculator),
            $this->trend(Stat::make('Item terjual', $count($now['items_sold'])), (string) $now['items_sold'], isset($before) ? (string) $before['items_sold'] : null, $versus)
                ->icon(Heroicon::OutlinedCube),
            $this->trend(Stat::make('Total diskon', Money::format($now['discount_total'])), $now['discount_total'], $before['discount_total'] ?? null, $versus, higherIsBetter: false)
                ->icon(Heroicon::OutlinedTag),
            Stat::make('Pajak & service', Money::format(Money::add($now['tax_total'], $now['service_total'])))
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->description('Pajak '.Money::format($now['tax_total']).' · Service '.Money::format($now['service_total']))
                ->color('gray'),
            Stat::make('Void', $count($now['void_count']).' transaksi')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->description($now['void_count'] > 0 ? Money::format($now['void_total']) : 'Aman')
                ->descriptionIcon($now['void_count'] > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($now['void_count'] > 0 ? 'warning' : 'success'),
            Stat::make('Stok habis', $count($outOfStock).' produk')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->description($outOfStock > 0 ? 'Lihat produk' : 'Aman')
                ->descriptionIcon($outOfStock > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($outOfStock > 0 ? 'danger' : 'success')
                ->url($outOfStock > 0 ? ProductResource::getUrl('index') : null),
        ];
    }

    /**
     * Deskripsi tren singkat ("Naik 12,5%" / "Turun 3%"); arah tetap dalam teks (aksesibilitas),
     * pembanding lengkap di tooltip & subjudul halaman.
     */
    private function trend(Stat $stat, string $now, ?string $before, ?string $versus, bool $higherIsBetter = true): Stat
    {
        if ($before === null || $versus === null) {
            return $stat->color('gray');
        }

        $change = ReportService::change($now, $before);

        if ($change === null) {
            // Pembanding 0: persen tak terdefinisi
            $text = Money::compare($now, '0') === 0 ? 'Tetap' : 'Tanpa pembanding';

            return $stat->description($text)->color('gray')->extraAttributes(['title' => "{$text} ({$versus})"]);
        }

        $up = Money::compare($change, '0') >= 0;
        $flat = Money::compare($change, '0') === 0;
        $good = $up === $higherIsBetter;
        $text = $flat ? 'Tetap' : ($up ? 'Naik ' : 'Turun ').str_replace('.', ',', ltrim($change, '-')).'%';

        return $stat
            ->description($text)
            ->descriptionIcon($flat ? null : ($up ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown))
            ->color($flat ? 'gray' : ($good ? 'success' : 'danger'))
            ->extraAttributes(['title' => "{$text} dari {$versus}"]);
    }
}
