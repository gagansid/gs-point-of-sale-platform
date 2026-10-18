<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\QueueMonitor;
use App\Services\Admin\SystemHealth;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Job di antrean (Dasbor & Sistem → Queue): yang sedang diproses, menunggu, atau terjadwal — admin tahu pekerjaan apa
 * yang sedang berjalan (backup, email). Hanya nama job; isi data job tidak ditampilkan. Tanpa polling (shared hosting):
 * tombol Muat ulang; cron memproses antrean tiap menit.
 */
final class QueuedJobsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 21;

    protected int|string|array $columnSpan = 'full';

    /** Link ke halaman Queue (dimatikan saat widget dipasang di halaman Queue sendiri). */
    public bool $showQueueLink = true;

    private const LIMIT = 20;

    private const STATUS = [
        'processing' => ['Diproses', 'info'],
        'pending' => ['Menunggu', 'warning'],
        'delayed' => ['Terjadwal', 'gray'],
    ];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Job di antrean')
            ->description('Diproses cron tiap menit. Menampilkan maks. '.self::LIMIT.' job: yang sedang diproses dulu, lalu yang paling lama menunggu')
            ->records(fn (): array => collect(app(SystemHealth::class)->jobs(self::LIMIT))->keyBy('id')->all())
            ->paginated(false)
            ->headerActions([
                Action::make('reload')->label('Muat ulang')->icon(Heroicon::OutlinedArrowPath)->link()
                    ->action(fn () => $this->resetTable()),
                Action::make('queue')->label('Buka Queue')->link()->url(QueueMonitor::getUrl())
                    ->visible(fn (): bool => $this->showQueueLink),
            ])
            ->emptyStateHeading('Antrean kosong')
            ->emptyStateDescription('Tidak ada job yang menunggu atau diproses')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->columns([
                TextColumn::make('label')->label('Job')->weight('medium')
                    ->description(fn (array $record): string => class_basename($record['class']).' · antrean '.$record['queue']),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUS[$state][0] ?? $state)
                    ->color(fn (string $state): string => self::STATUS[$state][1] ?? 'gray')
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'processing' => Heroicon::OutlinedArrowPath,
                        'delayed' => Heroicon::OutlinedCalendar,
                        default => Heroicon::OutlinedClock,
                    }),
                TextColumn::make('attempts')->label('Percobaan')->alignCenter()
                    ->color(fn (int $state): ?string => $state > 1 ? 'warning' : null),
                TextColumn::make('created_at')->label('Masuk')
                    ->formatStateUsing(fn (int $state): string => self::ago($state))
                    ->tooltip(fn (array $record): string => self::at($record['created_at'])),
                TextColumn::make('reserved_at')->label('Mulai / jadwal')
                    ->state(fn (array $record): string => match ($record['status']) {
                        'processing' => 'Mulai '.self::ago((int) $record['reserved_at']),
                        'delayed' => 'Jalan '.CarbonImmutable::createFromTimestamp($record['available_at'])->locale('id')->diffForHumans(),
                        default => '—',
                    }),
            ]);
    }

    private static function ago(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp)->locale('id')->diffForHumans();
    }

    private static function at(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp)->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H.i.s').' WIB';
    }
}
