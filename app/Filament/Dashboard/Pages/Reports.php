<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Report\ManageReportPresets;
use App\Enums\ReportTab;
use App\Exceptions\ReportTooLargeException;
use App\Filament\Dashboard\Pages\Concerns\InteractsWithReportFilters;
use App\Filament\Dashboard\Widgets\OrderTypesWidget;
use App\Filament\Dashboard\Widgets\OutletComparisonWidget;
use App\Filament\Dashboard\Widgets\PaymentMethodsChartWidget;
use App\Filament\Dashboard\Widgets\PeakHoursChartWidget;
use App\Filament\Dashboard\Widgets\Reports\CategoriesChartWidget;
use App\Filament\Dashboard\Widgets\Reports\CategoriesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ChannelsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\DiscountsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\EmployeesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\FinancialBreakdownWidget;
use App\Filament\Dashboard\Widgets\Reports\HeatmapWidget;
use App\Filament\Dashboard\Widgets\Reports\MemberStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\MemberTiersTableWidget;
use App\Filament\Dashboard\Widgets\Reports\MemberTopTableWidget;
use App\Filament\Dashboard\Widgets\Reports\MemberVouchersTableWidget;
use App\Filament\Dashboard\Widgets\Reports\OptionsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\PaymentMethodsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\PeakHoursTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ProductsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ProfitStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\ProfitTableWidget;
use App\Filament\Dashboard\Widgets\Reports\PurchasesByProductTableWidget;
use App\Filament\Dashboard\Widgets\Reports\PurchasesBySupplierTableWidget;
use App\Filament\Dashboard\Widgets\Reports\PurchaseStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\ReportStatsWidget;
use App\Filament\Dashboard\Widgets\Reports\ReportTrendChartWidget;
use App\Filament\Dashboard\Widgets\Reports\ShiftDifferencesTableWidget;
use App\Filament\Dashboard\Widgets\Reports\ShiftsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\SlowProductsTableWidget;
use App\Filament\Dashboard\Widgets\Reports\StockTableWidget;
use App\Filament\Dashboard\Widgets\Reports\TrendTableWidget;
use App\Filament\Dashboard\Widgets\Reports\VoidsTableWidget;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Outlet;
use App\Models\ReportPreset;
use App\Models\Shift;
use App\Models\User;
use App\Services\Report\ReportExporter;
use App\Support\CurrentOutlet;
use App\Support\ExportThrottle;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

/**
 * Menu Laporan: rekap transaksi per jenis laporan (tab, ReportTab) dengan filter live yang sama
 * dengan Beranda (InteractsWithReportFilters) + granularitas, kategori, produk, shift.
 *
 * Akses (Q49/Q50): report.view = semua tab & periode; report.view_shift (supervisor) = tab
 * operasional, maks. 31 hari ke belakang — dipotong di ReportContext (server), bukan hanya UI.
 * Angka dari ReportService (sama dengan API /reports, Beranda, dan file export).
 */
final class Reports extends Page
{
    use HasFiltersForm {
        mountHasFilters as private mountFiltersFromSession;
    }
    use HasIconBreadcrumbs;
    use InteractsWithReportFilters;

    /**
     * Tanpa #[Url]: state lengkap di session, URL memakai parameter ringkas (SyncsReportFiltersToUrl).
     *
     * @var array<string, mixed>|null
     */
    public ?array $filters = null;

    #[Url(except: 'summary')]
    public string $tab = 'summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = null;

    /** Tepat di bawah Beranda (Dashboard bawaan Filament = -2) */
    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    protected static ?string $slug = 'reports';

    public static function canAccess(): bool
    {
        $user = self::user();

        return $user !== null && ($user->can('report.view') || $user->can('report.view_shift'));
    }

    public function mount(): void
    {
        $this->tab = $this->activeTab()->value;
    }

    public function mountHasFilters(): void
    {
        $this->mountFiltersFromSession();
        $this->mountReportFilters();
    }

    public function updatedFilters(): void
    {
        $this->reportFiltersUpdated();
        // Ganti outlet bisa menyembunyikan tab Outlet
        $this->tab = $this->activeTab()->value;
    }

    public function updatedTab(): void
    {
        $this->tab = $this->activeTab()->value;
    }

    /** Tab aktif yang boleh dilihat; tab tidak dikenal/terlarang → tab pertama yang boleh. */
    public function activeTab(): ReportTab
    {
        $visible = ReportTab::visibleTabs(self::user());
        $tab = ReportTab::tryFrom($this->tab);

        return $tab !== null && in_array($tab, $visible, true) ? $tab : ($visible[0] ?? ReportTab::Summary);
    }

    /** @return list<ReportTab> */
    public function visibleTabs(): array
    {
        return ReportTab::visibleTabs(self::user());
    }

    protected function defaultReportPreset(): string
    {
        return 'this_month';
    }

    public function getSubheading(): string
    {
        $outlet = Outlet::query()->count() > 1
            ? (CurrentOutlet::selectedId() !== null ? CurrentOutlet::getOrFail()->name : 'Semua outlet').' · '
            : '';

        return $outlet.$this->reportSubheading();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            View::make('filament.dashboard.pages.reports.tabs'),
            // Satu kolom: tiap widget laporan selebar halaman (chart.md: 360px, tabel lebar)
            Grid::make(1)
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->widgetsFor($this->activeTab()))),
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            // Toolbar ringkas: periode di satu baris, filter lanjutan di panel samping
            Section::make()
                ->compact()
                ->columnSpanFull()
                ->extraAttributes(['class' => 'pos-report-filters'])
                ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
                ->schema([
                    $this->outletField(),
                    $this->presetField(),
                    $this->dateField('from', 'Dari'),
                    $this->dateField('to', 'Sampai'),
                    $this->compareField(),
                    $this->granularityField(),
                    View::make('filament.dashboard.pages.reports.active-filters')->columnSpanFull(),
                ]),
        ]);
    }

    /** @return list<Component> */
    protected function advancedFilterFields(): array
    {
        return [
            $this->orderTypeField(),
            $this->paymentMethodField(),
            $this->cashierField(),
            $this->categoryField(),
            $this->productField(),
            $this->shiftField(fn (): bool => $this->activeTab() === ReportTab::Shifts),
        ];
    }

    /** @return list<class-string> */
    private function widgetsFor(ReportTab $tab): array
    {
        return match ($tab) {
            ReportTab::Summary => [ReportStatsWidget::class, ReportTrendChartWidget::class, TrendTableWidget::class, FinancialBreakdownWidget::class],
            ReportTab::Products => [ProductsTableWidget::class, SlowProductsTableWidget::class],
            ReportTab::Categories => [CategoriesChartWidget::class, CategoriesTableWidget::class],
            ReportTab::Profit => [ProfitStatsWidget::class, ProfitTableWidget::class],
            ReportTab::Members => [MemberStatsWidget::class, MemberTopTableWidget::class, MemberVouchersTableWidget::class, MemberTiersTableWidget::class],
            ReportTab::Options => [OptionsTableWidget::class],
            ReportTab::PaymentMethods => [PaymentMethodsChartWidget::class, PaymentMethodsTableWidget::class],
            ReportTab::Employees => [EmployeesTableWidget::class],
            ReportTab::Shifts => [ShiftsTableWidget::class],
            ReportTab::Time => [PeakHoursChartWidget::class, PeakHoursTableWidget::class, HeatmapWidget::class],
            ReportTab::OrderTypes => [OrderTypesWidget::class, ChannelsTableWidget::class],
            ReportTab::Outlets => [OutletComparisonWidget::class],
            ReportTab::Discounts => [VoidsTableWidget::class, DiscountsTableWidget::class, ShiftDifferencesTableWidget::class],
            ReportTab::Stock => [StockTableWidget::class],
            ReportTab::Purchases => [PurchaseStatsWidget::class, PurchasesBySupplierTableWidget::class, PurchasesByProductTableWidget::class],
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshReportAction(),
            $this->resetReportFiltersAction(),
            $this->presetActions(),
            ActionGroup::make([
                $this->makeExportAction('excel_tab', 'Excel — tab ini', 'excel', lengkap: false),
                $this->makeExportAction('excel_full', 'Excel — lengkap', 'excel', lengkap: true),
                $this->makeExportAction('pdf_tab', 'PDF — tab ini', 'pdf', lengkap: false),
                $this->makeExportAction('pdf_full', 'PDF — lengkap', 'pdf', lengkap: true),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => (self::user()?->can('report.export') ?? false) && Features::enabled('report_export')),
        ];
    }

    /**
     * Preset filter per user (R8): simpan kombinasi periode/filter/tab, pakai lagi dengan sekali klik.
     */
    private function presetActions(): ActionGroup
    {
        $presets = fn (): array => ReportPreset::query()->where('user_id', self::user()?->id)->orderBy('name')->pluck('name', 'id')->all();
        $select = fn (): Select => Select::make('preset_id')->label('Preset')->options($presets)->required()->native(false);

        return ActionGroup::make([
            Action::make('applyPreset')->label('Pakai preset')->icon(Heroicon::OutlinedPlay)
                ->visible(fn (): bool => $presets() !== [])
                ->modalWidth('md')->modalSubmitActionLabel('Pakai')
                ->schema([$select()])
                ->action(function (array $data): void {
                    $user = self::user() ?? abort(403);
                    $saved = app(ManageReportPresets::class)->filtersOf($user, (string) $data['preset_id']);

                    if ($saved === null) {
                        Notification::make()->danger()->title('Preset tidak ditemukan')->send();

                        return;
                    }

                    // Tab hanya dipakai bila boleh dilihat user saat ini
                    $tab = ReportTab::tryFrom((string) ($saved['tab'] ?? ''));
                    if ($tab !== null && in_array($tab, ReportTab::visibleTabs($user), true)) {
                        $this->tab = $tab->value;
                    }

                    $this->fillReportFilters($this->normalizedReportFilters(Arr::except($saved, ['tab']), keepDates: true));
                    $this->reportFiltersUpdated();
                }),
            Action::make('savePreset')->label('Simpan filter ini')->icon(Heroicon::OutlinedBookmark)
                ->modalWidth('md')->modalSubmitActionLabel('Simpan')
                ->modalDescription('Periode, pembanding, filter lanjutan, dan tab disimpan. Outlet tetap mengikuti pemilih outlet.')
                ->schema([
                    TextInput::make('name')->label('Nama preset')->required()->maxLength(60)->placeholder('mis. Rekap mingguan'),
                ])
                ->action(fn (array $data, Action $action) => RunBusinessAction::run(function () use ($data): void {
                    app(ManageReportPresets::class)->save(self::user() ?? abort(403), (string) $data['name'], [...($this->filters ?? []), 'tab' => $this->activeTab()->value]);
                    Notification::make()->success()->title('Preset disimpan')->send();
                }, $action)),
            Action::make('deletePreset')->label('Hapus preset')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (): bool => $presets() !== [])
                ->modalWidth('md')->modalSubmitActionLabel('Hapus')
                ->schema([$select()])
                ->action(function (array $data): void {
                    app(ManageReportPresets::class)->delete(self::user() ?? abort(403), (string) $data['preset_id']);
                    Notification::make()->success()->title('Preset dihapus')->send();
                }),
        ])
            ->label('Preset')
            ->icon(Heroicon::OutlinedBookmark)
            ->color('gray')
            ->button();
    }

    /**
     * Export sinkron (Q52): filter & batas periode sama dengan layar; data terlalu besar ditolak
     * dengan notifikasi "Persempit rentang tanggal".
     */
    private function makeExportAction(string $name, string $label, string $format, bool $lengkap): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($format === 'pdf' ? Heroicon::OutlinedDocumentText : Heroicon::OutlinedTableCells)
            ->action(function () use ($format, $lengkap): ?Response {
                $user = self::user();
                abort_unless($user?->can('report.export') ?? false, 403);

                if (($wait = ExportThrottle::attempt($user)) !== null) {
                    Notification::make()->danger()->title(ExportThrottle::message($wait))->send();

                    return null;
                }

                try {
                    $exporter = app(ReportExporter::class);
                    $tab = $lengkap ? null : $this->activeTab();

                    return $format === 'pdf'
                        ? $exporter->pdf($user, $this->reportContext(), $tab)
                        : $exporter->excel($user, $this->reportContext(), $tab);
                } catch (ReportTooLargeException $e) {
                    Notification::make()->danger()->title($e->getMessage())
                        ->body('Batas '.number_format($e->limit, 0, ',', '.').' baris per file.')->send();

                    return null;
                }
            });
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
