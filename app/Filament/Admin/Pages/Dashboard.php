<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Services\Admin\DashboardCache;
use App\Services\Admin\PlatformMetrics;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Dasbor super admin: langganan & pendapatan, pertumbuhan bisnis, aktivitas platform, kesehatan sistem. Angka lintas
 * tenant di-cache 10 menit (DashboardCache) agar ringan di shared hosting; tombol "Muat ulang data" menghitung ulang.
 */
final class Dashboard extends BaseDashboard
{
    use HasIconBreadcrumbs;

    // Nama route tetap filament.admin.pages.dashboard seperti Dashboard bawaan
    protected static ?string $slug = 'dashboard';

    protected static ?string $title = 'Dasbor';

    public function getSubheading(): string
    {
        return 'Data per '.DashboardCache::generatedAt()->setTimezone(PlatformMetrics::timezone())->locale('id')->translatedFormat('j M Y, H.i')
            .' · diperbarui otomatis tiap 10 menit';
    }

    /** @return array<string, int> */
    public function getColumns(): array
    {
        return ['default' => 1, 'lg' => 2];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Muat ulang data')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function (): void {
                    DashboardCache::flush();
                    Notification::make()->success()->title('Data dasbor dihitung ulang')->send();
                    $this->redirect(self::getUrl(), navigate: true);
                }),
        ];
    }
}
