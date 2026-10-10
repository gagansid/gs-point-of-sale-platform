<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Report\ReportRequest;
use App\Services\Report\ReportService;
use App\Support\ApiResponse;
use App\Support\CurrentOutlet;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * Semua angka dari ReportService (sama dengan beranda dashboard web & Excel).
 * Filter opsional di semua endpoint: order_type, payment_method_ids[], user_ids[].
 */
#[Group('Laporan')]
final class ReportController extends Controller
{
    /**
     * Ringkasan penjualan.
     *
     * Permission report.view. from/to = tanggal lokal outlet (default hari ini, maks. 366 hari).
     * Omzet = order selesai pada rentang; void dilaporkan terpisah.
     */
    public function summary(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();
        $filters = $request->filters();

        return ApiResponse::success([
            'period' => $request->period(),
            ...$reports->summary($start, $end, $filters),
            'daily' => $reports->daily($start, $end, $request->period()['timezone'], $filters),
        ]);
    }

    /**
     * Penjualan per produk.
     *
     * Urut omzet terbesar.
     */
    public function products(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'products' => $reports->products($start, $end, null, $request->filters())]);
    }

    /**
     * Penjualan per metode bayar.
     */
    public function paymentMethods(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'payment_methods' => $reports->paymentMethods($start, $end, $request->filters())]);
    }

    /**
     * Ringkasan beranda (dashboard).
     *
     * KPI + periode pembanding (compare: previous_period default, previous_year, none) + persen perubahan,
     * tren (per jam bila from = to, selain itu per hari), jam ramai, metode bayar, top 10 produk,
     * tipe order, dan perbandingan outlet (hanya bila semua outlet). Persen perubahan null bila pembanding 0.
     */
    public function dashboard(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();
        $filters = $request->filters();
        $timezone = CurrentOutlet::timezone();
        $period = $request->reportPeriod();
        $hourlyTrend = $period->isSingleDay();

        $summary = $reports->summary($start, $end, $filters);
        $hourly = $reports->hourly($start, $end, $timezone, $filters);

        $comparison = $request->comparison();
        $previous = null;
        $previousTrend = null;

        if ($comparison !== null) {
            [$prevStart, $prevEnd] = $comparison->utcRange();
            $previous = $reports->summary($prevStart, $prevEnd, $filters);
            $previousTrend = $hourlyTrend
                ? $reports->hourly($prevStart, $prevEnd, $timezone, $filters)
                : $reports->daily($prevStart, $prevEnd, $timezone, $filters);
        }

        $changes = [];
        foreach (['revenue', 'order_count', 'average', 'items_sold', 'discount_total', 'void_total'] as $key) {
            $changes[$key] = $previous !== null ? ReportService::change((string) $summary[$key], (string) $previous[$key]) : null;
        }

        return ApiResponse::success([
            'period' => [...$request->period(), 'granularity' => $hourlyTrend ? 'hour' : 'day'],
            'comparison' => $comparison !== null ? ['from' => $comparison->from, 'to' => $comparison->to] : null,
            'summary' => $summary,
            'previous_summary' => $previous,
            'changes' => $changes,
            'trend' => $hourlyTrend ? $hourly : $reports->daily($start, $end, $timezone, $filters),
            'previous_trend' => $previousTrend,
            'hourly' => $hourly,
            'payment_methods' => $reports->paymentMethods($start, $end, $filters),
            'top_products' => $reports->products($start, $end, 10, $filters),
            'order_types' => $reports->orderTypes($start, $end, $filters),
            'outlets' => CurrentOutlet::selectedId() === null ? $reports->outlets($start, $end, $filters) : null,
        ]);
    }

    /**
     * Penjualan per jam.
     *
     * Omzet & transaksi per jam lokal outlet (0–23) sepanjang rentang: untuk melihat jam ramai.
     */
    public function hourly(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success([
            'period' => $request->period(),
            'hours' => $reports->hourly($start, $end, CurrentOutlet::timezone(), $request->filters()),
        ]);
    }

    /**
     * Temuan audit.
     *
     * Void (pelaku, approver, alasan), diskon dengan approval PIN, dan shift dengan selisih kas
     * atau ditutup paksa. Masing-masing 20 terbaru; jumlah total di *_count.
     */
    public function audit(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success([
            'period' => $request->period(),
            ...$reports->audit($start, $end, 20, $request->filters()),
        ]);
    }
}
