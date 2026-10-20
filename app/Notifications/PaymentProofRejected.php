<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\MailDetails;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Admin menolak bukti transfer (Q126) → owner diberi alasan & diminta mengirim ulang sebelum jatuh tempo. */
final class PaymentProofRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $number;

    public readonly string $businessName;

    public readonly string $amount;

    public readonly string $reason;

    public readonly string $dueDate;

    public function __construct(SubscriptionInvoice $invoice, ?Tenant $tenant)
    {
        $this->number = $invoice->number;
        $this->businessName = $tenant->name ?? 'Bisnis Anda';
        $this->amount = Money::format((string) $invoice->amount);
        $this->reason = (string) $invoice->proof_rejected_reason;
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
            ->subject("Bukti transfer {$this->number} perlu dikirim ulang")
            ->greeting('Bukti transfer perlu dikirim ulang')
            ->line("Bukti transfer untuk tagihan **{$this->number}** dari {$this->businessName} belum bisa kami verifikasi.")
            ->line(MailDetails::note('Alasan dari tim gs.POS', $this->reason))
            ->line(MailDetails::table(['Nomor tagihan' => $this->number, 'Jatuh tempo' => $this->dueDate, 'Total' => $this->amount], total: 'Total'))
            ->line("Silakan periksa kembali lalu kirim ulang bukti transfer sebelum **{$this->dueDate}** agar langganan tidak terhenti.")
            ->action('Kirim ulang bukti', SubscriptionSettings::getUrl(panel: 'dashboard'))
            ->line('Bila dana sudah terpotong dari rekening Anda, balas email ini dengan menyertakan mutasi rekening.');
    }
}
