<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use Filament\Widgets\Widget;

/**
 * Laporan › Waktu: heatmap hari-dalam-minggu × jam (jumlah transaksi). Intensitas dalam 5 level
 * kelas CSS (tanpa style inline); angka tetap tampil di tooltip & teks pembaca layar.
 */
final class HeatmapWidget extends Widget
{
    use ReportWidget;

    public const DAYS = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.widgets.reports.heatmap';

    /** @return array{grid: array<int, array<int, int>>, max: int, hours: list<int>, busiest: array{day: int, hour: int, count: int}|null} */
    public function getHeatmap(): array
    {
        [$start, $end] = $this->context()->range();
        $grid = app(ReportService::class)->heatmap($start, $end, CurrentOutlet::timezone(), $this->context()->filters);

        $max = 0;
        $busiest = null;
        $active = [];
        foreach ($grid as $day => $hours) {
            foreach ($hours as $hour => $count) {
                if ($count > 0) {
                    $active[] = $hour;
                }
                if ($count > $max) {
                    $max = $count;
                    $busiest = ['day' => $day, 'hour' => $hour, 'count' => $count];
                }
            }
        }

        // Jam operasional saja (min. 07–21) agar grid tidak penuh kolom kosong
        $first = $active !== [] ? min(min($active), 7) : 7;
        $last = $active !== [] ? max(max($active), 21) : 21;

        return ['grid' => $grid, 'max' => $max, 'hours' => range($first, $last), 'busiest' => $busiest];
    }

    /** Level intensitas 0–4 untuk kelas CSS. */
    public static function level(int $count, int $max): int
    {
        return $count === 0 || $max === 0 ? 0 : (int) min(4, ceil($count / $max * 4));
    }
}
