<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\ManageBackups;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Sistem → Backup: daftar backup (database + gambar produk), backup manual lewat antrean, unduh,
 * hapus. Jadwal otomatis: backup:run 02.00, backup:clean 03.00, backup:monitor 04.00 WIB.
 */
final class BackupManager extends Page
{
    use HasIconBreadcrumbs;

    protected string $view = 'filament.admin.pages.backup-manager';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Backup';

    protected static ?string $title = 'Backup';

    protected static ?string $slug = 'backups';

    protected static ?int $navigationSort = 2;

    /** Zip backup berisi hash kata sandi & PIN: wajib terenkripsi di production. */
    public function isEncrypted(): bool
    {
        return filled(config('backup.backup.password'));
    }

    /** @return list<array{path: string, name: string, size: int, created: int}> */
    public function backups(): array
    {
        return app(ManageBackups::class)->list();
    }

    public function getSubheading(): string
    {
        return 'Backup otomatis harian 02.00 WIB · unduh berkala ke tempat lain';
    }

    public function download(string $name): StreamedResponse
    {
        return Storage::disk(ManageBackups::DISK)->download(app(ManageBackups::class)->path($name));
    }

    public function deleteAction(): Action
    {
        // Ikon aksi baris standar (row-actions.md): ikon 32px abu, merah saat hover
        return Action::make('delete')->label('Hapus')->color('danger')->icon(Heroicon::OutlinedTrash)
            ->iconButton()->tooltip('Hapus')
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Hapus backup')
            ->modalHeading(fn (array $arguments): string => 'Hapus '.($arguments['name'] ?? '').'?')
            ->schema([
                TextInput::make('current_password')->label('Kata sandi Anda')->password()->required()->currentPassword(guard: 'admin'),
            ])
            ->action(function (array $arguments): void {
                app(ManageBackups::class)->delete((string) ($arguments['name'] ?? ''));
                Notification::make()->success()->title('Backup dihapus')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')->label('Backup sekarang')->icon(Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->modalDescription('Backup dijalankan antrean (cron tiap menit) dan muncul di daftar dalam beberapa menit.')
                ->action(function (): void {
                    app(ManageBackups::class)->queue();
                    Notification::make()->success()->title('Backup masuk antrean')->body('Muat ulang halaman ini beberapa menit lagi')->send();
                }),
        ];
    }
}
