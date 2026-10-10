<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Enums\OrderType;
use App\Exports\SalesReportExport;
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
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Report\ReportFilters;
use App\Services\Report\ReportPeriod;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Beranda panel /dashboard: ringkasan bisnis untuk owner/manager (finance, audit, operasional).
 *
 * Filter (outlet, periode, pembanding, tipe order, metode bayar, kasir) bersifat live: setiap
 * perubahan langsung menghitung ulang semua widget lewat $pageFilters (tanpa polling — shared
 * hosting). Filter tersimpan di query string & session. Outlet memakai pilihan sidebar yang sama
 * (CurrentOutlet, ADR 0010), jadi menu lain ikut berpindah outlet.
 */
final class Home extends Dashboard
{
    use HasFiltersForm {
        mountHasFilters as private mountFiltersFromSession;
    }
    use HasIconBreadcrumbs;

    // Nama route tetap filament.dashboard.pages.dashboard seperti Dashboard bawaan
    protected static ?string $slug = 'dashboard';

    protected static ?string $title = 'Beranda';

    /** Jam hitung terakhir (zona outlet), tampil di subjudul. */
    public string $refreshedAt = '';

    /** Filter session/URL → nilai aman: preset relatif hari ini, outlet mengikuti sidebar. */
    public function mountHasFilters(): void
    {
        $this->mountFiltersFromSession();

        $this->filters = $this->normalizedFilters($this->filters ?? []);
        $this->getFiltersForm()->fill($this->filters);
        session()->put($this->getFiltersSessionKey(), $this->filters);

        $this->touchRefreshedAt();
    }

    public function updatedFilters(): void
    {
        $this->touchRefreshedAt();

        session()->put($this->getFiltersSessionKey(), $this->filters);
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
        if (! self::canViewReports()) {
            return null;
        }

        $period = $this->period();
        $parts = [$period->label()];

        if (($comparison = $period->comparison((string) ($this->filters['compare'] ?? 'none'))) !== null) {
            $parts[] = 'dibanding '.$comparison->label();
        }

        if ($this->refreshedAt !== '') {
            $parts[] = 'diperbarui '.$this->refreshedAt;
        }

        return implode(' · ', $parts);
    }

    public function filtersForm(Schema $schema): Schema
    {
        $outlets = $this->outletOptions();

        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->visible(self::canViewReports())
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->schema([
                    Select::make('outlet')
                        ->label('Outlet')
                        ->options(['all' => 'Semua outlet', ...$outlets])
                        ->selectablePlaceholder(false)
                        ->prefixIcon(Heroicon::OutlinedBuildingStorefront)
                        ->visible(count($outlets) > 1)
                        ->afterStateUpdated(fn (?string $state) => $this->switchOutlet($state)),
                    Select::make('preset')
                        ->label('Periode')
                        ->options(ReportPeriod::PRESETS)
                        ->selectablePlaceholder(false)
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === null || $state === 'custom') {
                                return;
                            }

                            $period = ReportPeriod::preset($state, CurrentOutlet::today());
                            $set('from', $period->from);
                            $set('to', $period->to);
                        }),
                    DatePicker::make('from')
                        ->label('Dari tanggal')
                        ->native(false)
                        ->displayFormat('j M Y')
                        ->closeOnDateSelection()
                        ->maxDate(fn (): string => CurrentOutlet::today())
                        ->suffixIcon(Heroicon::OutlinedCalendarDays)
                        ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom')),
                    DatePicker::make('to')
                        ->label('Sampai tanggal')
                        ->native(false)
                        ->displayFormat('j M Y')
                        ->closeOnDateSelection()
                        ->maxDate(fn (): string => CurrentOutlet::today())
                        ->suffixIcon(Heroicon::OutlinedCalendarDays)
                        ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom')),
                    Select::make('compare')
                        ->label('Bandingkan dengan')
                        ->options(ReportPeriod::COMPARES)
                        ->selectablePlaceholder(false),
                    Select::make('order_type')
                        ->label('Tipe order')
                        ->options(OrderType::class)
                        ->placeholder('Semua tipe'),
                    Select::make('payment_method_ids')
                        ->label('Metode bayar')
                        ->multiple()
                        ->options(fn (): array => PaymentMethod::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('Semua metode'),
                    Select::make('user_ids')
                        ->label('Kasir')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('Semua kasir'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Muat ulang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(self::canViewReports())
                ->action(function (): void {
                    $this->touchRefreshedAt();
                    $this->dispatch('dashboard-refresh');
                }),
            Action::make('resetFilters')
                ->label('Reset filter')
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->visible(self::canViewReports())
                ->action(function (): void {
                    $this->filters = $this->normalizedFilters([]);
                    $this->getFiltersForm()->fill($this->filters);
                    $this->updatedFilters();
                }),
            Action::make('export')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => self::user()?->can('report.export') ?? false)
                ->action(function (): BinaryFileResponse {
                    $period = $this->period();
                    [$start, $end] = $period->utcRange();

                    return Excel::download(
                        new SalesReportExport(app(ReportService::class), $period->from, $period->to, CurrentOutlet::timezone(), $start, $end,
                            ReportFilters::fromArray($this->filters ?? [])),
                        "laporan-penjualan-{$period->from}-{$period->to}.xlsx",
                    );
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $input): array
    {
        $today = CurrentOutlet::today();
        $preset = is_string($input['preset'] ?? null) && isset(ReportPeriod::PRESETS[$input['preset']]) ? $input['preset'] : 'today';
        $period = $preset === 'custom'
            ? ReportPeriod::normalize($input['from'] ?? null, $input['to'] ?? null, $today)
            : ReportPeriod::preset($preset, $today);
        $compare = is_string($input['compare'] ?? null) && isset(ReportPeriod::COMPARES[$input['compare']]) ? $input['compare'] : 'previous_period';
        $extra = ReportFilters::fromArray($input);

        return [
            // Sumber kebenaran outlet = pemilih sidebar (session), bukan filter tersimpan
            'outlet' => CurrentOutlet::selectedId() ?? 'all',
            'preset' => $preset,
            'from' => $period->from,
            'to' => $period->to,
            'compare' => $compare,
            'order_type' => $extra->orderType?->value,
            'payment_method_ids' => $extra->paymentMethodIds,
            'user_ids' => $extra->userIds,
        ];
    }

    private function period(): ReportPeriod
    {
        $filters = $this->filters ?? [];
        $today = CurrentOutlet::today();

        return ($filters['preset'] ?? 'today') === 'custom'
            ? ReportPeriod::normalize($filters['from'] ?? null, $filters['to'] ?? null, $today)
            : ReportPeriod::preset((string) ($filters['preset'] ?? 'today'), $today);
    }

    /** Ganti outlet = ganti pilihan sidebar (outlet di luar akses user → 404 di CurrentOutlet::choose). */
    private function switchOutlet(?string $outletId): void
    {
        $user = self::user();

        if ($user === null) {
            return;
        }

        CurrentOutlet::choose($user, $outletId === null || $outletId === 'all' ? null : $outletId);

        // Label pemilih outlet di sidebar ikut berubah tanpa reload
        $this->dispatch('pos-outlet-changed', label: CurrentOutlet::selectedId() !== null ? CurrentOutlet::getOrFail()->name : 'Semua outlet');
    }

    /** @return array<string, string> */
    private function outletOptions(): array
    {
        $user = self::user();

        return $user !== null ? $user->accessibleOutlets()->pluck('name', 'id')->all() : [];
    }

    private function touchRefreshedAt(): void
    {
        $this->refreshedAt = now(CurrentOutlet::timezone())->format('H.i');
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
