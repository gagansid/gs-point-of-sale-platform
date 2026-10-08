<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Report\ReportRequest;
use App\Services\Report\ReportService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

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

        return ApiResponse::success([
            'period' => $request->period(),
            ...$reports->summary($start, $end),
            'daily' => $reports->daily($start, $end, $request->period()['timezone']),
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

        return ApiResponse::success(['period' => $request->period(), 'products' => $reports->products($start, $end)]);
    }

    /**
     * Penjualan per metode bayar.
     */
    public function paymentMethods(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'payment_methods' => $reports->paymentMethods($start, $end)]);
    }
}
