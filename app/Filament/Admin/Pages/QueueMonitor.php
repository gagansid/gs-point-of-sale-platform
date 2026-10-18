<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\ManageFailedJobs;
use App\Filament\Admin\Widgets\QueuedJobsWidget;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\FailedJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Sistem → Queue: antrean (driver database, dijalankan cron `queue:work --stop-when-empty` tiap menit), tabel job
 * yang sedang diproses/menunggu (QueuedJobsWidget), dan job gagal: lihat error, ulangi, hapus.
 */
final class QueueMonitor extends Page implements HasActions, HasSchemas, HasTable
{
    use HasIconBreadcrumbs;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.queue-monitor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Queue';

    protected static ?string $title = 'Queue';

    protected static ?string $slug = 'queue';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /** @return array{pending: int, oldest: string|null} */
    public function pending(): array
    {
        $oldest = DB::table('jobs')->min('created_at');

        return [
            'pending' => DB::table('jobs')->count(),
            'oldest' => $oldest !== null ? now()->setTimestamp((int) $oldest)->diffForHumans() : null,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FailedJob::query())
            ->heading('Job gagal')
            ->defaultSort('failed_at', 'desc')
            ->columns([
                TextColumn::make('job')->label('Job')->weight('medium')
                    ->state(fn (FailedJob $record): string => class_basename($record->displayName()))
                    ->description(fn (FailedJob $record): string => $record->errorSummary())
                    ->wrap(),
                TextColumn::make('queue')->label('Antrean')->badge()->color('gray'),
                TextColumn::make('failed_at')->label('Gagal')->since()->dateTimeTooltip('j M Y, H.i')->sortable(),
            ])
            ->recordActions([
                Action::make('detail')->label('Detail')->iconButton()->icon(Heroicon::OutlinedEye)->tooltip('Lihat error')
                    ->modalHeading(fn (FailedJob $record): string => $record->displayName())
                    ->modalContent(fn (FailedJob $record) => view('filament.admin.pages.failed-job-detail', ['job' => $record]))
                    ->modalSubmitAction(false)->modalWidth('4xl'),
                Action::make('retry')->label('Ulangi')->iconButton()->icon(Heroicon::OutlinedArrowPath)->tooltip('Ulangi')
                    ->action(function (FailedJob $record): void {
                        app(ManageFailedJobs::class)->retry([$record->uuid]);
                        Notification::make()->success()->title('Job dimasukkan lagi ke antrean')->send();
                    }),
                Action::make('forget')->label('Hapus')->iconButton()->icon(Heroicon::OutlinedTrash)->color('danger')->tooltip('Hapus')
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => app(ManageFailedJobs::class)->forget($record)),
            ])
            ->toolbarActions([
                BulkAction::make('retrySelected')->label('Ulangi terpilih')->icon(Heroicon::OutlinedArrowPath)
                    ->action(fn (Collection $records) => app(ManageFailedJobs::class)->retry($records->pluck('uuid')->values()->all())),
            ])
            ->emptyStateHeading('Tidak ada job gagal')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /** Job yang sedang diproses / menunggu (nama job saja), di atas daftar job gagal. */
    protected function getHeaderWidgets(): array
    {
        return [QueuedJobsWidget::make(['showQueueLink' => false])];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAll')->label('Ulangi semua')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn (): bool => FailedJob::query()->exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ManageFailedJobs::class)->retryAll();
                    Notification::make()->success()->title('Semua job gagal dimasukkan lagi ke antrean')->send();
                }),
            Action::make('flush')->label('Hapus semua')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (): bool => FailedJob::query()->exists())
                ->requiresConfirmation()
                ->modalDescription('Job gagal dihapus permanen tanpa dijalankan ulang.')
                ->action(fn () => app(ManageFailedJobs::class)->flush()),
        ];
    }
}
