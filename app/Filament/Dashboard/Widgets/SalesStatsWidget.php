<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Models\Product;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Beranda: omzet, transaksi, rata-rata hari ini (dibanding kemarin) dan produk stok habis
 * (stat-widget.md). Angka dari ReportService.
 */
final class SalesStatsWidget extends StatsOverviewWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    // Shared hosting: tanpa polling otomatis (stat-widget.md)
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $reports = app(ReportService::class);
        $today = CurrentOutlet::today();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

        $now = $reports->summary(...CurrentOutlet::utcRange($today, $today));
        $before = $reports->summary(...CurrentOutlet::utcRange($yesterday, $yesterday));

        $outOfStock = Product::query()->active()->where('track_stock', true)->where('stock_qty', '<=', 0)->count();

        return [
            $this->trend(Stat::make('Omzet hari ini', Money::format($now['revenue'])), $now['revenue'], $before['revenue'])
                ->icon(Heroicon::OutlinedBanknotes),
            $this->trend(Stat::make('Transaksi', number_format($now['order_count'], 0, ',', '.')), (string) $now['order_count'], (string) $before['order_count'])
                ->icon(Heroicon::OutlinedReceiptPercent),
            Stat::make('Rata-rata transaksi', Money::format($now['average']))
                ->description($now['void_count'] > 0 ? "{$now['void_count']} transaksi void hari ini" : 'Tidak ada void hari ini')
                ->color($now['void_count'] > 0 ? 'warning' : 'gray'),
            Stat::make('Stok habis', number_format($outOfStock, 0, ',', '.').' produk')
                ->description($outOfStock > 0 ? 'Lihat daftar produk' : 'Semua stok aman')
                ->descriptionIcon($outOfStock > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($outOfStock > 0 ? 'danger' : 'success')
                ->url($outOfStock > 0 ? ProductResource::getUrl('index') : null),
        ];
    }

    /** Deskripsi tren dalam teks (aksesibilitas: tidak hanya panah/warna). */
    private function trend(Stat $stat, string $now, string $before): Stat
    {
        if (Money::compare($before, '0') === 0) {
            return $stat->description('Belum ada pembanding kemarin')->color('gray');
        }

        $percent = Money::round(bcdiv(bcmul(Money::sub($now, $before), '100', 6), $before, 6), 0);
        $up = Money::compare($now, $before) >= 0;

        return $stat
            ->description(($up ? 'Naik ' : 'Turun ').ltrim($percent, '-').'% dari kemarin')
            ->descriptionIcon($up ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown)
            ->color($up ? 'success' : 'danger');
    }
}
