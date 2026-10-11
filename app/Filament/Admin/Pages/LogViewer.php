<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\ClearLogFile;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Support\LogReader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Sistem → Log: membaca log Laravel tanpa SSH (SPEC panel admin). 200 entri terbaru dari 1 MB terakhir
 * file, filter level & cari teks; unduh file utuh; kosongkan file (konfirmasi kata sandi).
 */
final class LogViewer extends Page
{
    use HasIconBreadcrumbs;

    protected string $view = 'filament.admin.pages.log-viewer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Log';

    protected static ?string $title = 'Log';

    protected static ?string $slug = 'logs';

    protected static ?int $navigationSort = 5;

    #[Url]
    public ?string $file = null;

    #[Url]
    public string $level = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        // File tidak dikenal (mis. URL diubah) → file terbaru
        if (LogReader::path($this->file) === null) {
            $this->file = array_key_first(LogReader::files());
        }
    }

    /** @return list<array{time: string, env: string, level: string, message: string, context: string}> */
    public function entries(): array
    {
        $path = LogReader::path($this->file);

        return $path === null ? [] : LogReader::entries($path, in_array($this->level, LogReader::LEVELS, true) ? $this->level : null, $this->search);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label('Unduh')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (): bool => LogReader::path($this->file) !== null)
                ->action(fn (): BinaryFileResponse => response()->download((string) LogReader::path($this->file))),
            Action::make('clear')->label('Kosongkan')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (): bool => LogReader::path($this->file) !== null)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Kosongkan {$this->file}?")
                ->modalDescription('Isi file dihapus permanen. Unduh dulu bila masih diperlukan.')
                ->schema([
                    TextInput::make('current_password')->label('Kata sandi Anda')->password()->required()->currentPassword(guard: 'admin'),
                ])
                ->action(function (): void {
                    app(ClearLogFile::class)->handle((string) $this->file);
                    Notification::make()->success()->title('File log dikosongkan')->send();
                }),
        ];
    }
}
