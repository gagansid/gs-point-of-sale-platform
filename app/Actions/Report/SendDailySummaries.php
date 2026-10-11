<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Models\User;
use App\Notifications\DailySalesSummary;
use App\Services\Report\DailySummaryBuilder;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kirim ringkasan harian lewat email (Q57, R5). Dipanggil command `pos:daily-summary` tiap jam (cron cPanel
 * `schedule:run`): user dikirimi sekali per tanggal lokal, setelah jam `pos.daily_summary.hour` di zona waktu
 * outlet pertamanya, berisi rekap KEMARIN. Penerima: aktif, opt-in, email terverifikasi, izin report.view,
 * tenant aktif. Tanpa aktivitas kemarin = tidak dikirim (tanggal tetap ditandai).
 */
final class SendDailySummaries
{
    public function __construct(private readonly DailySummaryBuilder $builder) {}

    /** @return int jumlah email yang diantrekan */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $hour = (int) config('pos.daily_summary.hour');
        $sent = 0;

        User::allTenants()
            ->with('tenant')
            ->where('daily_summary_email', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereNotNull('email_verified_at')
            ->chunkById(100, function ($users) use ($now, $hour, &$sent): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    if (! $user->tenant->isActive() || ! $user->hasPermission('report.view')) {
                        continue;
                    }

                    $timezone = $user->accessibleOutlets()->where('is_active', true)->value('timezone');

                    if (! is_string($timezone)) {
                        continue;
                    }

                    $local = $now->setTimezone($timezone);
                    $today = $local->toDateString();

                    if ($local->hour < $hour || $user->last_daily_summary_on?->toDateString() === $today) {
                        continue;
                    }

                    // Klaim atomik: command yang berjalan ganda tetap satu email per hari
                    $claimed = DB::table('users')->where('id', $user->id)
                        ->where(fn ($q) => $q->whereNull('last_daily_summary_on')->orWhere('last_daily_summary_on', '<', $today))
                        ->update(['last_daily_summary_on' => $today]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    $summary = TenantContext::run($user->tenant_id, fn (): array => $this->builder->build($user, $local->subDay()->toDateString()));

                    if ($summary['has_activity']) {
                        $user->notify(new DailySalesSummary($user->tenant->name, $summary));
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
