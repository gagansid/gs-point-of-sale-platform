<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Support\MailDetails;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Owner mengirim bukti transfer manual (Q126) → super admin diminta memverifikasi. Data disalin sebagai teks (antrean
 * tanpa tenant context); file bukti tidak dilampirkan (dibuka lewat panel, URL bertanda tangan).
 */
final class PaymentProofSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $number;

    public readonly string $businessName;

    public readonly string $description;

    public readonly string $amount;

    public readonly string $sender;

    public readonly ?string $note;

    public readonly string $submittedAt;

    public function __construct(SubscriptionInvoice $invoice, ?Tenant $tenant)
    {
        $tz = (string) config('pos.default_timezone');
        $this->number = $invoice->number;
        $this->businessName = $tenant->name ?? 'Bisnis';
        $this->description = $invoice->description();
        $this->amount = Money::format((string) $invoice->amount);
        $this->sender = (string) $invoice->proof_sender;
        $this->note = $invoice->proof_note;
        $this->submittedAt = ($invoice->proof_submitted_at ?? now())->setTimezone($tz)->locale('id')->translatedFormat('j F Y, H.i T');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Verifikasi pembayaran {$this->number} — {$this->businessName}")
            ->greeting('Bukti transfer menunggu verifikasi')
            ->line("**{$this->businessName}** mengirim bukti transfer untuk tagihan berikut:")
            ->line(MailDetails::table([
                'Nomor tagihan' => $this->number,
                'Bisnis' => $this->businessName,
                'Rincian' => $this->description,
                'Pengirim' => $this->sender,
                'Catatan owner' => $this->note,
                'Dikirim' => $this->submittedAt,
                'Total' => $this->amount,
            ], total: 'Total'));

        return $mail
            ->action('Verifikasi pembayaran', SubscriptionInvoiceResource::getUrl('index', panel: 'admin'))
            ->line('Cocokkan nominal & rekening tujuan dengan mutasi bank sebelum menandai lunas. Bila tidak sesuai, tolak bukti dengan alasan yang jelas.');
    }
}
