<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Billing\InvoicePdf;
use App\Support\MailDetails;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tagihan lunas (Q126) → owner menerima konfirmasi + kuitansi PDF terlampir (dibuat saat email dikirim dari data
 * terbaru; tagihan dimuat tanpa tenant scope karena antrean berjalan tanpa tenant context).
 */
final class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $invoiceId;

    public readonly string $number;

    public readonly string $businessName;

    public readonly string $description;

    public readonly string $amount;

    public readonly string $paidAt;

    public readonly ?string $periodEnd;

    public function __construct(SubscriptionInvoice $invoice, ?Tenant $tenant)
    {
        $tz = (string) config('pos.default_timezone');
        $this->invoiceId = $invoice->id;
        $this->number = $invoice->number;
        $this->businessName = $tenant->name ?? 'Bisnis Anda';
        $this->description = $invoice->description();
        $this->amount = Money::format((string) $invoice->amount);
        $this->paidAt = ($invoice->paid_at ?? now())->setTimezone($tz)->locale('id')->translatedFormat('j F Y, H.i T');
        $this->periodEnd = $invoice->period_end?->setTimezone($tz)->locale('id')->translatedFormat('j F Y');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Pembayaran diterima — {$this->number}")
            ->greeting('Pembayaran diterima')
            ->line("Terima kasih! Pembayaran tagihan **{$this->number}** untuk {$this->businessName} sudah kami terima.")
            ->line(MailDetails::table([
                'Nomor tagihan' => $this->number,
                'Rincian' => $this->description,
                'Dibayar' => $this->paidAt,
                'Aktif sampai' => $this->periodEnd,
                'Jumlah dibayar' => $this->amount,
            ], total: 'Jumlah dibayar'));

        $invoice = SubscriptionInvoice::query()->withoutGlobalScopes()->with('items')->find($this->invoiceId);
        if ($invoice !== null) {
            $mail->attachData(app(InvoicePdf::class)->render($invoice), InvoicePdf::filename($invoice), ['mime' => 'application/pdf']);
        }

        return $mail
            ->line('Kuitansi resmi terlampir dalam format PDF dan bisa diunduh kapan saja dari halaman Langganan.')
            ->action('Buka halaman Langganan', SubscriptionSettings::getUrl(panel: 'dashboard'));
    }
}
