<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Exports\SalesReportExport;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Menu Laporan: ringkasan, harian, per produk, per metode bayar + export Excel.
 * Angka dari ReportService (sama dengan API /reports dan widget beranda).
 */
final class Reports extends Page
{
    use HasFiltersForm;
    use HasIconBreadcrumbs;

    protected string $view = 'filament.dashboard.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan penjualan';

    protected static ?string $slug = 'reports';

    public static function canAccess(): bool
    {
        return self::user()?->can('report.view') ?? false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->columns(['md' => 4])->components([
            DatePicker::make('from')->label('Dari')->native(false)->displayFormat('j M Y')
                ->default(fn (): string => CarbonImmutable::parse(CurrentOutlet::today())->startOfMonth()->toDateString()),
            DatePicker::make('to')->label('Sampai')->native(false)->displayFormat('j M Y')
                ->default(fn (): string => CurrentOutlet::today()),
        ]);
    }

    /**
     * Rentang terpakai: urutan dibetulkan bila terbalik, dibatasi 366 hari seperti API.
     *
     * @return array{from: string, to: string}
     */
    public function period(): array
    {
        $today = CurrentOutlet::today();
        $from = CarbonImmutable::parse((string) ($this->filters['from'] ?? $today));
        $to = CarbonImmutable::parse((string) ($this->filters['to'] ?? $today));

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 365) {
            $from = $to->subDays(365);
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        $reports = app(ReportService::class);
        [$start, $end] = $this->range();

        return [
            'period' => $this->period(),
            'summary' => $reports->summary($start, $end),
            'products' => $reports->products($start, $end),
            'payment_methods' => $reports->paymentMethods($start, $end),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => self::user()?->can('report.export') ?? false)
                ->action(function (): BinaryFileResponse {
                    ['from' => $from, 'to' => $to] = $this->period();
                    [$start, $end] = $this->range();

                    return Excel::download(
                        new SalesReportExport(app(ReportService::class), $from, $to, CurrentOutlet::timezone(), $start, $end),
                        "laporan-penjualan-{$from}-{$to}.xlsx",
                    );
                }),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        ['from' => $from, 'to' => $to] = $this->period();

        return CurrentOutlet::utcRange($from, $to);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
