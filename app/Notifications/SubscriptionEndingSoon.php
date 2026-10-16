<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pengingat langganan/trial akan berakhir (ADR 0024). */
final class SubscriptionEndingSoon extends Notification
{
    use Queueable;

    public function __construct(private readonly Tenant $tenant, private readonly int $days) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->tenant->subscription_ends_at?->setTimezone(config('pos.default_timezone'))->locale('id')->translatedFormat('j F Y');

        return (new MailMessage)
            ->subject("Langganan {$this->tenant->name} berakhir {$this->days} hari lagi")
            ->greeting('Halo,')
            ->line("Langganan gs.POS untuk {$this->tenant->name} berakhir pada {$date}.")
            ->line('Setelah itu data tetap bisa dilihat, tetapi transaksi baru tidak bisa dicatat sampai diperpanjang.')
            ->action('Perpanjang langganan', SubscriptionSettings::getUrl(panel: 'dashboard'))
            ->line('Abaikan email ini bila sudah diperpanjang.');
    }
}
