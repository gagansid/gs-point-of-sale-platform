<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Pages\OutletSettings;
use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Models\User;
use App\Services\Report\ReportService;
use Filament\Widgets\Widget;

/**
 * Beranda: progres target omzet harian & bulanan per outlet (Q56, R6). Selalu hari ini & bulan berjalan
 * (bukan filter periode). Belum ada target: ajakan mengatur bagi yang berizin outlet.settings, selain itu disembunyikan.
 */
final class SalesTargetWidget extends Widget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.widgets.sales-target';

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    /** @return list<array{outlet_id: string, name: string, daily_target: string|null, daily_revenue: string, daily_percent: string|null, monthly_target: string|null, monthly_revenue: string, monthly_percent: string|null, month_pace: string}> */
    public function getTargets(): array
    {
        /** @var list<array{outlet_id: string, name: string, daily_target: string|null, daily_revenue: string, daily_percent: string|null, monthly_target: string|null, monthly_revenue: string, monthly_percent: string|null, month_pace: string}> */
        return $this->rows ??= app(ReportService::class)->salesTargets();
    }

    public function canSetTargets(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('outlet.settings');
    }

    public function settingsUrl(): string
    {
        return OutletSettings::getUrl();
    }
}
