<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tagihan langganan baru dari super admin (ADR 0024, Q109) ke owner aktif. Lewat antrean database; data disalin
 * sebagai teks agar antrean tidak perlu memuat ulang model ber-tenant.
 */
final class InvoiceCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $number;

    public readonly string $businessName;

    public readonly string $description;

    public readonly string $amount;

    public readonly string $dueDate;

    public function __construct(SubscriptionInvoice $invoice, Tenant $tenant)
    {
        $this->number = $invoice->number;
        $this->businessName = $tenant->name;
        $this->description = $invoice->kind->getLabel().' paket '.$invoice->plan_name.' ('.$invoice->billing_cycle->getLabel().')';
        $this->amount = Money::format($invoice->amount);
        $this->dueDate = $invoice->due_at->setTimezone((string) config('pos.default_timezone'))->locale('id')->translatedFormat('j F Y');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tagihan {$this->number} — {$this->businessName}")
            ->greeting('Halo,')
            ->line("Ada tagihan langganan gs.POS baru untuk {$this->businessName}:")
            ->line("{$this->description}: {$this->amount}, jatuh tempo {$this->dueDate}.")
            ->action('Lihat & bayar tagihan', SubscriptionSettings::getUrl(panel: 'dashboard'))
            ->line('Tagihan menunggu sebelumnya (bila ada) otomatis dibatalkan.');
    }
}
