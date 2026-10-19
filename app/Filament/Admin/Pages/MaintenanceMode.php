<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\SetMaintenanceMode;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Sistem → Maintenance: matikan sementara semua halaman & API (mis. saat migrasi besar). Admin yang
 * mengaktifkan langsung diarahkan ke URL secret sehingga browsernya tetap bisa membuka panel.
 */
final class MaintenanceMode extends Page
{
    use HasIconBreadcrumbs;

    protected string $view = 'filament.admin.pages.maintenance-mode';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Maintenance';

    protected static ?string $title = 'Maintenance';

    protected static ?string $slug = 'maintenance';

    protected static ?int $navigationSort = 4;

    public function getSubheading(): string
    {
        return 'Matikan sementara semua halaman & API pelanggan, mis. saat migrasi besar';
    }

    public function isEnabled(): bool
    {
        return app(SetMaintenanceMode::class)->isEnabled();
    }

    public function bypassUrl(): ?string
    {
        $secret = app(SetMaintenanceMode::class)->secret();

        return $secret !== null ? url('/'.$secret) : null;
    }

    protected function getHeaderActions(): array
    {
        $enabled = $this->isEnabled();

        return [
            Action::make('toggle')
                ->label($enabled ? 'Matikan maintenance' : 'Aktifkan maintenance')
                ->icon($enabled ? Heroicon::OutlinedPlayCircle : Heroicon::OutlinedPauseCircle)
                ->color($enabled ? 'success' : 'danger')
                ->requiresConfirmation()
                ->modalHeading($enabled ? 'Matikan maintenance?' : 'Aktifkan maintenance?')
                ->modalDescription($enabled
                    ? 'Dashboard, kasir web, dan aplikasi kasir bisa dipakai lagi.'
                    : 'Semua pelanggan tidak bisa membuka dashboard, kasir web, maupun API (aplikasi menampilkan MAINTENANCE). Transaksi offline di tablet tetap tersimpan dan dikirim setelah selesai.')
                // Konfirmasi kata sandi: sesi yang dibajak tidak bisa mematikan layanan
                ->schema([
                    TextInput::make('current_password')->label('Kata sandi Anda')->password()->required()->currentPassword(guard: 'admin'),
                ])
                ->action(function () use ($enabled): void {
                    $maintenance = app(SetMaintenanceMode::class);

                    if ($enabled) {
                        $maintenance->disable();
                        Notification::make()->success()->title('Maintenance dimatikan')->send();
                        $this->redirect(self::getUrl());

                        return;
                    }

                    // URL secret memasang cookie bypass di browser admin ini, lalu kembali ke panel
                    $this->redirect(url('/'.$maintenance->enable()));
                }),
        ];
    }
}
