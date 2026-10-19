<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Concerns;

use App\Services\Admin\PlatformMetrics;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;

/**
 * Gaya chart Dasbor /admin (chart.md + panduan dataviz): satu seri warna primary, batang tipis berujung bulat 4px,
 * grid horizontal samar, tanpa legenda (judul menamai seri), tooltip nilai lengkap, animasi mati (duration 0).
 */
trait AdminChart
{
    /**
     * Warna seri mengikuti tema saat chart digambar (light: navy #2D4282, dark: indigo #6F86C2 selaras token dark).
     * Dipasang di `elements` (scriptable) karena data chart dikirim sebagai JSON tanpa fungsi. Kutip tunggal saja:
     * opsi dirender di atribut HTML berkutip ganda.
     */
    private const ELEMENTS = <<<'JS'
        elements: {
            bar: {
                backgroundColor: () => document.documentElement.classList.contains('dark') ? '#6F86C2' : '#2D4282',
                hoverBackgroundColor: () => document.documentElement.classList.contains('dark') ? '#8EA2E0' : '#066FD1',
            },
            line: {
                borderColor: () => document.documentElement.classList.contains('dark') ? '#8EA2E0' : '#2D4282',
                backgroundColor: () => document.documentElement.classList.contains('dark') ? 'rgba(142, 162, 224, 0.14)' : 'rgba(45, 66, 130, 0.08)',
            },
        },
        JS;

    /**
     * @param  list<float|int>  $values
     * @return array<string, mixed>
     */
    private static function barDataset(string $label, array $values): array
    {
        return [
            'label' => $label,
            'data' => $values,
            'borderRadius' => 4,
            'borderSkipped' => 'start',
            'maxBarThickness' => 28,
        ];
    }

    /**
     * Label bulan "Okt 26" dari key "2026-10".
     *
     * @param  list<string>  $months
     * @return list<string>
     */
    private static function monthLabels(array $months): array
    {
        return array_map(fn (string $m): string => CarbonImmutable::createFromFormat('Y-m', $m, PlatformMetrics::timezone())->locale('id')->translatedFormat('M y'), $months);
    }

    /** Sumbu "Rp1,2 jt / Rp850 rb", tooltip Rupiah lengkap. suggestedMax: data nol tetap berskala wajar (bukan "Rp0 rb" berulang). */
    private static function rupiahOptions(): RawJs
    {
        return self::chartOptions(<<<'JS'
            {
                animation: { duration: 0 },
                {ELEMENTS}
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => 'Rp' + Math.round(ctx.parsed.y).toLocaleString('id-ID') } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 8 } },
                    y: { beginAtZero: true, suggestedMax: 100000, border: { display: false }, ticks: { maxTicksLimit: 5, callback: (v) => v === 0 ? 'Rp0' : v >= 1000000
                        ? 'Rp' + (v / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'
                        : 'Rp' + (v / 1000).toLocaleString('id-ID') + ' rb' } },
                },
            }
        JS);
    }

    /** Sumbu bilangan bulat (jumlah bisnis/transaksi). */
    private static function countOptions(string $unit): RawJs
    {
        // Opsi dirender di atribut HTML berkutip ganda: pakai kutip tunggal, satuan hanya huruf/spasi
        $unit = preg_replace('/[^\pL ]/u', '', $unit);

        return self::chartOptions(<<<JS
            {
                animation: { duration: 0 },
                {ELEMENTS}
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => Math.round(ctx.parsed.y ?? ctx.parsed.x).toLocaleString('id-ID') + ' {$unit}' } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 8 } },
                    y: { beginAtZero: true, suggestedMax: 5, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 6 } },
                },
            }
        JS);
    }

    /** Bar horizontal (kategori di sumbu Y, nama panjang terbaca), sumbu X bilangan bulat. */
    private static function horizontalCountOptions(string $unit): RawJs
    {
        // Opsi dirender di atribut HTML berkutip ganda: pakai kutip tunggal, satuan hanya huruf/spasi
        $unit = preg_replace('/[^\pL ]/u', '', $unit);

        return self::chartOptions(<<<JS
            {
                indexAxis: 'y',
                animation: { duration: 0 },
                {ELEMENTS}
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => Math.round(ctx.parsed.x).toLocaleString('id-ID') + ' {$unit}' } },
                },
                scales: {
                    x: { beginAtZero: true, suggestedMax: 5, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 6 } },
                    y: { grid: { display: false } },
                },
            }
        JS);
    }

    /** Opsi chart + warna seri sesuai tema (ELEMENTS). */
    private static function chartOptions(string $options): RawJs
    {
        return RawJs::make(str_replace('{ELEMENTS}', self::ELEMENTS, $options));
    }
}
