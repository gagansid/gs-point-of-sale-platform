<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Actions\Admin\ManageBackups;
use App\Actions\Admin\SetMaintenanceMode;
use App\Support\LogReader;
use App\Support\QueuedJobLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kesehatan sistem untuk Dasbor /admin (D4): cron/scheduler, antrean, backup, error log, mode perawatan, lingkungan
 * server. Shared hosting: satu cron `schedule:run` tiap menit menjalankan semuanya, jadi heartbeat scheduler adalah
 * tanda paling penting (tanpa cron: antrean, backup, pengingat tagihan, add-on berhenti — semua diam).
 */
final class SystemHealth
{
    public const HEARTBEAT_KEY = 'system:scheduler-heartbeat';

    /** Dipanggil schedule:run tiap menit (routes/console.php). */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, CarbonImmutable::now()->getTimestamp());
    }

    /** @return array{last: CarbonImmutable|null, healthy: bool} sehat bila berdetak ≤ 3 menit lalu */
    public function scheduler(): array
    {
        $ts = Cache::get(self::HEARTBEAT_KEY);
        $last = is_numeric($ts) ? CarbonImmutable::createFromTimestamp((int) $ts) : null;

        return ['last' => $last, 'healthy' => $last !== null && $last->gte(CarbonImmutable::now()->subMinutes(3))];
    }

    /** @return array{pending: int, processing: int, delayed: int, oldest: CarbonImmutable|null, failed: int, failed_24h: int} */
    public function queue(): array
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->min('created_at');

        return [
            'pending' => DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->count(),
            'processing' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'delayed' => DB::table('jobs')->whereNull('reserved_at')->where('available_at', '>', $now)->count(),
            'oldest' => $oldest !== null ? CarbonImmutable::createFromTimestamp((int) $oldest) : null,
            'failed' => DB::table('failed_jobs')->count(),
            'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count(),
        ];
    }

    /**
     * Job di antrean: sedang diproses dulu, lalu yang paling lama menunggu.
     *
     * @return list<array{id: string, label: string, class: string, queue: string, status: string, attempts: int, created_at: int, reserved_at: int|null, available_at: int}>
     */
    public function jobs(int $limit = 20): array
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return DB::table('jobs')
            ->orderByRaw('reserved_at IS NULL')->orderBy('available_at')->orderBy('id')
            ->limit($limit)->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->map(function (object $job) use ($now): array {
                $name = QueuedJobLabel::fromPayload((string) $job->payload);

                return [
                    'id' => (string) $job->id,
                    'label' => $name['label'],
                    'class' => $name['class'],
                    'queue' => (string) $job->queue,
                    'status' => match (true) {
                        $job->reserved_at !== null => 'processing',
                        (int) $job->available_at > $now => 'delayed',
                        default => 'pending',
                    },
                    'attempts' => (int) $job->attempts,
                    'created_at' => (int) $job->created_at,
                    'reserved_at' => $job->reserved_at !== null ? (int) $job->reserved_at : null,
                    'available_at' => (int) $job->available_at,
                ];
            })->values()->all();
    }

    /** @return array{name: string, size: int, created: CarbonImmutable}|null backup terbaru */
    public function latestBackup(): ?array
    {
        try {
            $latest = app(ManageBackups::class)->list()[0] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $latest === null ? null : ['name' => $latest['name'], 'size' => $latest['size'], 'created' => CarbonImmutable::createFromTimestamp($latest['created'])];
    }

    /** Jumlah error (error, critical, alert, emergency) 24 jam terakhir di file log terbaru; di-cache 10 menit. */
    public function errors24h(): int
    {
        return DashboardCache::remember('errors-24h', function (): int {
            $path = LogReader::path(array_key_first(LogReader::files()));
            if ($path === null) {
                return 0;
            }

            $since = CarbonImmutable::now()->subDay();
            $levels = ['error', 'critical', 'alert', 'emergency'];

            return collect(LogReader::entries($path, null, null, 1000))
                ->filter(fn (array $e): bool => in_array($e['level'], $levels, true) && self::after($e['time'], $since))
                ->count();
        });
    }

    public function maintenance(): bool
    {
        return app(SetMaintenanceMode::class)->isEnabled();
    }

    /**
     * @return array{php: string, laravel: string, env: string, debug: bool, cache: string, queue: string, mail: string,
     *     disk_free: int|null, disk_total: int|null}
     */
    public function environment(): array
    {
        $path = storage_path();
        // disk_*_space bisa dimatikan di shared hosting (disable_functions)
        $free = function_exists('disk_free_space') ? @disk_free_space($path) : false;
        $total = function_exists('disk_total_space') ? @disk_total_space($path) : false;

        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'env' => (string) app()->environment(),
            'debug' => (bool) config('app.debug'),
            'cache' => (string) config('cache.default'),
            'queue' => (string) config('queue.default'),
            'mail' => (string) config('mail.default'),
            'disk_free' => $free === false ? null : (int) $free,
            'disk_total' => $total === false ? null : (int) $total,
        ];
    }

    private static function after(string $time, CarbonImmutable $since): bool
    {
        try {
            return CarbonImmutable::parse($time)->gte($since);
        } catch (Throwable) {
            return false;
        }
    }
}
