<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\BackupManager;
use App\Filament\Admin\Pages\LogViewer;
use App\Filament\Admin\Pages\MaintenanceMode;
use App\Filament\Admin\Pages\QueueMonitor;
use App\Services\Admin\SystemHealth;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Dasbor /admin (D4): kesehatan sistem. Status selalu dengan ikon + teks (bukan warna saja); kartu bisa diklik ke halaman
 * Sistem terkait. Tidak di-cache (selalu terkini) kecuali hitungan error log.
 */
final class SystemHealthWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 20;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Kesehatan sistem';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $health = app(SystemHealth::class);
        $scheduler = $health->scheduler();
        $queue = $health->queue();
        $backup = $health->latestBackup();
        $errors = $health->errors24h();
        $env = $health->environment();
        $maintenance = $health->maintenance();
        $backupFresh = $backup !== null && $backup['created']->gte(now()->subHours(26));
        $queueStuck = $queue['oldest'] !== null && $queue['oldest']->lte(now()->subMinutes(5));
        $diskPercent = $env['disk_free'] !== null && $env['disk_total'] ? (int) round($env['disk_free'] * 100 / $env['disk_total']) : null;

        return [
            Stat::make('Cron / scheduler', $scheduler['healthy'] ? 'Berjalan' : 'Tidak berjalan')
                ->icon($scheduler['healthy'] ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->description($scheduler['last'] !== null ? 'Detak terakhir '.$scheduler['last']->locale('id')->diffForHumans() : 'Belum pernah berdetak — pasang cron schedule:run tiap menit')
                ->color($scheduler['healthy'] ? 'success' : 'danger'),
            Stat::make('Antrean menunggu', number_format($queue['pending'], 0, ',', '.'))
                ->icon($queueStuck ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedQueueList)
                ->description(number_format($queue['processing'], 0, ',', '.').' diproses'
                    .($queue['delayed'] > 0 ? ' · '.$queue['delayed'].' terjadwal' : '')
                    .($queue['oldest'] !== null ? ' · tertua '.self::age($queue['oldest']) : ''))
                ->color($queueStuck ? 'warning' : 'gray')
                ->url(QueueMonitor::getUrl()),
            Stat::make('Job gagal', number_format($queue['failed'], 0, ',', '.'))
                ->icon($queue['failed'] > 0 ? Heroicon::OutlinedXCircle : Heroicon::OutlinedCheckCircle)
                ->description($queue['failed'] > 0 ? $queue['failed_24h'].' dalam 24 jam · ulangi/hapus di Queue' : 'Tidak ada')
                ->color($queue['failed'] > 0 ? 'danger' : 'success')
                ->url(QueueMonitor::getUrl()),
            Stat::make('Backup terakhir', $backup !== null ? self::age($backup['created']) : 'Belum ada')
                ->icon($backupFresh ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->description($backup !== null ? Number::fileSize($backup['size'], precision: 1).' · '.$backup['created']->setTimezone('Asia/Jakarta')->translatedFormat('j M Y, H.i') : 'Jadwal harian 02.00 WIB, atau Backup sekarang')
                ->color($backupFresh ? 'success' : 'warning')
                ->url(BackupManager::getUrl()),
            Stat::make('Error log (24 jam)', number_format($errors, 0, ',', '.'))
                ->icon($errors > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->description($errors > 0 ? 'Periksa di Log (level error ke atas)' : 'Tidak ada error')
                ->color($errors > 0 ? 'warning' : 'success')
                ->url(LogViewer::getUrl(['level' => 'error'])),
            Stat::make('Mode perawatan', $maintenance ? 'Aktif' : 'Nonaktif')
                ->icon($maintenance ? Heroicon::OutlinedWrenchScrewdriver : Heroicon::OutlinedCheckCircle)
                ->description($maintenance ? 'Aplikasi & API tidak bisa dipakai pelanggan' : 'Aplikasi melayani normal')
                ->color($maintenance ? 'warning' : 'success')
                ->url(MaintenanceMode::getUrl()),
            Stat::make('Lingkungan', 'PHP '.$env['php'])
                ->icon($env['debug'] && $env['env'] === 'production' ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedServer)
                ->description('Laravel '.$env['laravel'].' · '.$env['env'].($env['debug'] ? ' · DEBUG AKTIF' : '').' · queue '.$env['queue'].' · mail '.$env['mail'])
                // APP_DEBUG di production membocorkan detail error: wajib dimatikan
                ->color($env['debug'] && $env['env'] === 'production' ? 'danger' : 'gray'),
            Stat::make('Ruang disk', $diskPercent !== null ? $diskPercent.'% kosong' : 'Tidak terbaca')
                ->icon($diskPercent !== null && $diskPercent < 10 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCircleStack)
                ->description($env['disk_free'] !== null ? Number::fileSize($env['disk_free'], precision: 1).' dari '.Number::fileSize((int) $env['disk_total'], precision: 1) : 'Fungsi disk dimatikan hosting')
                ->color($diskPercent !== null && $diskPercent < 10 ? 'danger' : 'gray'),
        ];
    }

    /** Umur ringkas untuk nilai kartu: "12 menit lalu", "5 jam lalu", "7 hari lalu". */
    private static function age(CarbonInterface $at): string
    {
        $minutes = (int) $at->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => 'Baru saja',
            $minutes < 60 => $minutes.' menit lalu',
            $minutes < 1440 => intdiv($minutes, 60).' jam lalu',
            default => intdiv($minutes, 1440).' hari lalu',
        };
    }
}
