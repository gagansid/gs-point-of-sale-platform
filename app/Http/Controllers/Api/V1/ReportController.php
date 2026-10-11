<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Report\ReportRequest;
use App\Models\Shift;
use App\Services\Report\ReportExporter;
use App\Services\Report\ReportService;
use App\Services\Report\ShiftRecap;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use App\Support\CurrentOutlet;
use App\Support\ExportThrottle;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Tren omzet.
     *
     * granularity: hour (rentang 1 hari) | day | week (Senin, ISO) | month; tidak cocok dengan rentang → bawaan
     * (≤ 1 hari jam, ≤ 62 hari hari, ≤ 180 hari minggu, sisanya bulan). Pembanding sejajar per bucket.
     */
    public function trend(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();
        $filters = $request->filters();
        $timezone = CurrentOutlet::timezone();
        $granularity = $request->granularity();
        $comparison = $request->comparison();

        return ApiResponse::success([
            'period' => [...$request->period(), 'granularity' => $granularity],
            'comparison' => $comparison !== null ? ['from' => $comparison->from, 'to' => $comparison->to] : null,
            'trend' => $reports->trend($start, $end, $timezone, $granularity, $filters),
            'previous_trend' => $comparison !== null ? $reports->trend(...[...$comparison->utcRange(), $timezone, $granularity, $filters]) : null,
        ]);
    }

    /**
     * Penjualan per kategori.
     *
     * Dari snapshot kategori saat transaksi (Q51); produk tanpa kategori = "Tanpa kategori".
     */
    public function categories(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'categories' => $reports->categories($start, $end, $request->filters())]);
    }

    /**
     * Laba kotor per produk.
     *
     * Dari snapshot harga modal saat transaksi (Q53). net_sales = setelah diskon order, tanpa pajak & service.
     * Produk tanpa harga modal: gross_profit & margin null, dilaporkan di uncosted_qty. Hanya report.view.
     */
    public function profit(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), ...$reports->profit($start, $end, $request->filters())]);
    }

    /**
     * Penjualan per kanal.
     *
     * Kasir (`pos`) vs pesan dari QR meja (`self_order`, ADR 0014): transaksi, omzet, dan bagian yang dibayar online
     * (Midtrans). Hanya report.view.
     */
    public function channels(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'channels' => $reports->channels($start, $end, $request->filters())]);
    }

    /**
     * Laporan member.
     *
     * Program member (ADR 0013): member vs non-member, member aktif/berulang/baru, poin (didapat, ditukar,
     * kedaluwarsa), utang poin saat ini (saldo aktif × nilai poin, seluruh bisnis), voucher, gratis stempel,
     * 20 member teratas, pemakaian voucher, sebaran tier. Hanya report.view.
     */
    public function members(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), ...$reports->members($start, $end, $request->filters())]);
    }

    /**
     * Opsi & add-on terlaris.
     *
     * qty = dipilih × qty item; revenue = tambahan omzet dari opsi.
     */
    public function options(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'options' => $reports->options($start, $end, $request->filters())]);
    }

    /**
     * Kinerja karyawan.
     *
     * Per kasir (pembuat order): transaksi, omzet, rata-rata, diskon diberikan, void.
     */
    public function employees(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'employees' => $reports->employees($start, $end, $request->filters())]);
    }

    /**
     * Rekap shift.
     *
     * Shift yang dibuka pada rentang: kasir, kas awal, penjualan tunai, kas seharusnya vs aktual, selisih.
     */
    public function shifts(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'shifts' => $reports->shifts($start, $end, $request->filters())]);
    }

    /**
     * Rekap satu shift (X/Z-report).
     *
     * Pemilik shift (report.view_own, termasuk kasir), report.view, atau report.view_shift (≤ 31 hari).
     * type = x_report (shift berjalan) / z_report (sudah ditutup). Shift outlet lain → 404.
     */
    public function shiftRecap(Request $request, Shift $shift, ShiftRecap $recap): JsonResponse
    {
        $user = ApiActor::user($request);

        if (! ShiftRecap::canView($user, $shift)) {
            throw new AuthorizationException;
        }

        return ApiResponse::success($recap->for($shift));
    }

    /**
     * PDF rekap satu shift (X/Z-report).
     *
     * Izin sama dengan rekap shift (pemilik shift, report.view, report.view_shift ≤ 31 hari).
     * Respons `application/pdf` (unduhan), bukan JSON. Maks. 10 export/menit per user (bersama export
     * dashboard), lebih → 429 `TOO_MANY_REQUESTS`.
     */
    public function shiftRecapPdf(Request $request, Shift $shift, ShiftRecap $recap, ReportExporter $exporter): Response
    {
        $user = ApiActor::user($request);

        if (! ShiftRecap::canView($user, $shift)) {
            throw new AuthorizationException;
        }

        if (($wait = ExportThrottle::attempt($user)) !== null) {
            throw BusinessException::of(ErrorCode::TooManyRequests, ExportThrottle::message($wait));
        }

        return $exporter->shiftPdf($user, $shift, $recap);
    }

    /**
     * Kepadatan hari × jam.
     *
     * heatmap[hari ISO 1–7][jam 0–23] = jumlah transaksi (jam lokal outlet).
     */
    public function heatmap(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'heatmap' => $reports->heatmap($start, $end, CurrentOutlet::timezone(), $request->filters())]);
    }

    /**
     * Pergerakan stok.
     *
     * Per produk: terjual, kembali dari void, penyesuaian, stok kini (null = tidak dilacak), is_low.
     */
    public function stock(ReportRequest $request, ReportService $reports): JsonResponse
    {
        [$start, $end] = $request->utcRange();

        return ApiResponse::success(['period' => $request->period(), 'stock' => $reports->stock($start, $end, $request->filters())]);
    }
}
