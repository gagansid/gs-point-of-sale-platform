<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Exports\SalesReportExport;
use App\Filament\Dashboard\Pages\Concerns\InteractsWithReportFilters;
use App\Filament\Dashboard\Widgets\AuditWidget;
use App\Filament\Dashboard\Widgets\DailyRevenueWidget;
use App\Filament\Dashboard\Widgets\OrderTypesWidget;
use App\Filament\Dashboard\Widgets\OutletComparisonWidget;
use App\Filament\Dashboard\Widgets\PaymentMethodsChartWidget;
use App\Filament\Dashboard\Widgets\PeakHoursChartWidget;
use App\Filament\Dashboard\Widgets\RevenueChartWidget;
use App\Filament\Dashboard\Widgets\SalesStatsWidget;
use App\Filament\Dashboard\Widgets\SetupChecklistWidget;
use App\Filament\Dashboard\Widgets\TopProductsWidget;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Beranda panel /dashboard: ringkasan bisnis untuk owner/manager (finance, audit, operasional).
 *
 * Filter (outlet, periode, pembanding, tipe order, metode bayar, kasir) bersifat live: setiap
 * perubahan langsung menghitung ulang semua widget lewat $pageFilters (tanpa polling — shared
 * hosting). Filter tersimpan di session; URL hanya berisi parameter ringkas yang bukan default
 * (SyncsReportFiltersToUrl). Outlet memakai pilihan sidebar yang sama (CurrentOutlet, ADR 0010),
 * jadi menu lain ikut berpindah outlet.
 */
final class Home extends Dashboard
{
    use HasFiltersForm {
        mountHasFilters as private mountFiltersFromSession;
    }
    use HasIconBreadcrumbs;
    use InteractsWithReportFilters;

    /**
     * Dideklarasi ulang TANPA #[Url] (bawaan HasFilters): state lengkap hanya di session,
     * URL memakai parameter ringkas dari SyncsReportFiltersToUrl.
     *
     * @var array<string, mixed>|null
     */
    public ?array $filters = null;

    // Nama route tetap filament.dashboard.pages.dashboard seperti Dashboard bawaan
    protected static ?string $slug = 'dashboard';

    protected static ?string $title = 'Beranda';

    /** Beranda = ringkasan bisnis (report.view); supervisor diarahkan ke Laporan (Q50). */
    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewReports();
    }

    public function mount(): void
    {
        if (! self::canViewReports() && Reports::canAccess()) {
            $this->redirect(Reports::getUrl());
        }
    }

    /** Filter session/URL → nilai aman: preset relatif hari ini, outlet mengikuti sidebar. */
    public function mountHasFilters(): void
    {
        $this->mountFiltersFromSession();
        $this->mountReportFilters();
    }

    public function updatedFilters(): void
    {
        $this->reportFiltersUpdated();
    }

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            SetupChecklistWidget::class,
            SalesStatsWidget::class,
            RevenueChartWidget::class,
            TopProductsWidget::class,
            PaymentMethodsChartWidget::class,
            PeakHoursChartWidget::class,
            OrderTypesWidget::class,
            DailyRevenueWidget::class,
            OutletComparisonWidget::class,
            AuditWidget::class,
        ];
    }

    /** @return array<string, int|null> */
    public function getColumns(): array
    {
        return ['default' => 1, 'lg' => 2];
    }

    /** Filter & grid widget selebar halaman (grid konten halaman ikut getColumns()). */
    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm')->columnSpanFull();
    }

    public function getWidgetsContentComponent(): Component
    {
        return parent::getWidgetsContentComponent()->columnSpanFull();
    }

    public function getSubheading(): ?string
    {
        return self::canViewReports() ? $this->reportSubheading() : null;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->visible(self::canViewReports())
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->schema([
                    $this->outletField(),
                    $this->presetField(),
                    $this->dateField('from', 'Dari tanggal'),
                    $this->dateField('to', 'Sampai tanggal'),
                    $this->compareField(),
                    $this->orderTypeField(),
                    $this->paymentMethodField(),
                    $this->cashierField(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshReportAction()->visible(self::canViewReports()),
            $this->resetReportFiltersAction()->visible(self::canViewReports()),
            Action::make('export')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => self::canViewReports() && (self::user()?->can('report.export') ?? false))
                ->action(function (): BinaryFileResponse {
                    $context = $this->reportContext();
                    [$start, $end] = $context->range();
                    $period = $context->period;

                    return Excel::download(
                        new SalesReportExport(app(ReportService::class), $period->from, $period->to, CurrentOutlet::timezone(), $start, $end, $context->filters),
                        "laporan-penjualan-{$period->from}-{$period->to}.xlsx",
                    );
                }),
        ];
    }

    private static function canViewReports(): bool
    {
        return self::user()?->can('report.view') ?? false;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
