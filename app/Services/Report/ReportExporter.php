<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\ReportTab;
use App\Exceptions\BusinessException;
use App\Exceptions\ReportTooLargeException;
use App\Exports\ReportSectionBuilder;
use App\Exports\ReportWorkbookExport;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export laporan Excel & PDF, sinkron (Q52). Hanya tab yang boleh dilihat user (supervisor: tab
 * operasional, batas 31 hari dari ReportContext). Data terlalu besar → ReportTooLargeException
 * ("Persempit rentang tanggal"), tidak pernah dipotong diam-diam.
 */
final class ReportExporter
{
    public const MAX_EXCEL_ROWS = 50000;

    public const MAX_PDF_ROWS = 5000;

    public function __construct(
        private readonly ReportSectionBuilder $sections,
        private readonly ReportService $reports,
    ) {}

    /**
     * Tab yang diekspor: satu tab (bila boleh dilihat) atau semua tab yang boleh ("lengkap").
     *
     * @return list<ReportTab>
     */
    public static function tabsFor(User $user, ?ReportTab $tab): array
    {
        $visible = ReportTab::visibleTabs($user);

        if ($tab === null) {
            return $visible;
        }

        return in_array($tab, $visible, true) ? [$tab] : [];
    }

    /** laporan-{tab|lengkap}-{from}-{to}.{ext} */
    public static function filename(?ReportTab $tab, ReportPeriod $period, string $extension): string
    {
        $name = $tab !== null ? str_replace('_', '-', $tab->value) : 'lengkap';

        return "laporan-{$name}-{$period->from}-{$period->to}.{$extension}";
    }

    /** @throws ReportTooLargeException|BusinessException FEATURE_NOT_AVAILABLE (ADR 0025) */
    public function excel(User $user, ReportContext $context, ?ReportTab $tab): Response
    {
        Features::ensure('report_export');
        $tabs = self::tabsFor($user, $tab);
        abort_if($tabs === [], 403);

        $sections = $this->sections->build($tabs, $context, self::MAX_EXCEL_ROWS);

        return Excel::download(new ReportWorkbookExport($this->info($user, $context, $tab), $sections), self::filename($tab, $context->period, 'xlsx'));
    }

    /** @throws ReportTooLargeException|BusinessException FEATURE_NOT_AVAILABLE (ADR 0025) */
    public function pdf(User $user, ReportContext $context, ?ReportTab $tab): Response
    {
        Features::ensure('report_export');
        $tabs = self::tabsFor($user, $tab);
        abort_if($tabs === [], 403);

        $sections = $this->sections->build($tabs, $context, self::MAX_PDF_ROWS);
        [$start, $end] = $context->range();
        $summary = $this->reports->summary($start, $end, $context->filters);
        // Tabel lebar (rekap shift, heatmap, karyawan) → landscape
        $wide = array_filter($sections, fn ($s): bool => count($s->headings) > 7) !== [];

        $pdf = Pdf::loadView('reports.pdf.report', [
            'title' => 'Laporan '.($tab !== null ? $tab->label() : 'lengkap'),
            'tenant' => (string) ($user->tenant->name ?? ''),
            'outlet' => $this->outletLabel(),
            'info' => $this->info($user, $context, $tab, withPrinter: false),
            'stats' => [
                ['Omzet', Money::format($summary['revenue'])],
                ['Transaksi', number_format($summary['order_count'], 0, ',', '.')],
                ['Rata-rata', Money::format($summary['average'])],
                ['Item terjual', number_format($summary['items_sold'], 0, ',', '.')],
            ],
            'sections' => $sections,
            'printedBy' => $user->name,
            'printedAt' => $this->now(),
        ])->setPaper('a4', $wide ? 'landscape' : 'portrait');

        return $this->stream($this->output($pdf), self::filename($tab, $context->period, 'pdf'));
    }

    /** PDF rekap satu shift (X/Z-report); izin dicek pemanggil lewat ShiftRecap::canView. */
    public function shiftPdf(User $user, Shift $shift, ShiftRecap $recap): Response
    {
        $data = $recap->for($shift);
        $type = $data['type'] === 'z_report' ? 'z-report' : 'x-report';

        $timezone = $shift->outlet->timezone ?? CurrentOutlet::timezone();
        $pdf = Pdf::loadView('reports.pdf.shift', [
            'recap' => $data,
            'tenant' => (string) ($user->tenant->name ?? ''),
            'timezone' => $timezone,
            'printedBy' => $user->name,
            'printedAt' => $this->now(),
        ])->setPaper('a4');

        return $this->stream($this->output($pdf), "laporan-shift-{$type}-".$shift->opened_at->setTimezone($timezone)->format('Y-m-d-Hi').'.pdf');
    }

    /**
     * Unduhan PDF sebagai stream: aksi Livewire (dashboard) hanya mendukung respons stream/berkas,
     * API juga menerima. Data laporan: jangan disimpan cache.
     */
    /** Nomor halaman "Hal. n / total" di kanan bawah (dompdf tidak mendukung counter(pages) di CSS). */
    private function output(\Barryvdh\DomPDF\PDF $pdf): string
    {
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        // Font merek (terdaftar lewat @font-face saat render), DejaVu Sans bila TTF tidak ada
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('Plus Jakarta Sans') ?? $metrics->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 27, 'Hal. {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.494, 0.533, 0.588]);

        return (string) $dompdf->output();
    }

    private function stream(string $content, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private']);
    }

    /** @return list<array{0: string, 1: string}> */
    private function info(User $user, ReportContext $context, ?ReportTab $tab, bool $withPrinter = true): array
    {
        $filters = ReportFilterLabels::describe($context->filters);

        return array_values(array_filter([
            ['Laporan', $tab !== null ? $tab->label() : 'Lengkap'],
            ['Bisnis', (string) ($user->tenant->name ?? '')],
            ['Outlet', $this->outletLabel()],
            ['Periode', $context->period->label().' ('.$context->period->from.' s/d '.$context->period->to.', '.CurrentOutlet::timezone().')'],
            ['Pembanding', $context->comparison !== null ? $context->comparison->label() : 'Tanpa pembanding'],
            ['Kelompok tren', ReportPeriod::GRANULARITIES[$context->granularity]],
            ['Filter', $filters !== [] ? implode(' · ', $filters) : 'Tanpa filter'],
            $withPrinter ? ['Dicetak oleh', $user->name] : null,
            $withPrinter ? ['Waktu cetak', $this->now()] : null,
        ]));
    }

    private function outletLabel(): string
    {
        return CurrentOutlet::selectedId() !== null ? (string) CurrentOutlet::getOrFail()->name : 'Semua outlet';
    }

    private function now(): string
    {
        return now(CurrentOutlet::timezone())->locale('id')->translatedFormat('j M Y H.i').' ('.CurrentOutlet::timezone().')';
    }
}
