<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ringkasan harian lewat email (Q57, R5): omzet, transaksi, void, selisih kas, dan target per outlet.
 * Lewat antrean database (cron schedule:run memproses antrean tiap menit).
 */
final class DailySalesSummary extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{date: string, has_activity: bool, outlets: list<array<string, mixed>>, totals: array<string, mixed>}  $summary  hasil DailySummaryBuilder
     */
    public function __construct(
        public readonly string $businessName,
        public readonly array $summary,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $date = CarbonImmutable::parse($this->summary['date'])->locale('id')->translatedFormat('l, j F Y');

        return (new MailMessage)
            ->subject("Ringkasan {$date} — {$this->businessName}")
            ->markdown('mail.daily-summary', [
                'name' => $notifiable->name,
                'business' => $this->businessName,
                'date' => $date,
                'summary' => $this->summary,
                'reportsUrl' => route('filament.dashboard.pages.reports'),
            ]);
    }
}
