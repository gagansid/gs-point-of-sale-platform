<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Concerns;

use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Deskripsi tren stat singkat ("Naik 12,5%" / "Turun 3%" / "Tetap"); arah tetap dalam teks
 * (aksesibilitas), pembanding lengkap di tooltip & subjudul halaman.
 */
trait DescribesTrend
{
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
