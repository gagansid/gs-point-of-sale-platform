<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link verifikasi email karyawan yang ditambahkan/diubah emailnya oleh owner/manager (SPEC Q46).
 * Memakai route & tanda tangan yang sama dengan verifikasi owner (berlaku 3 hari, tanpa login).
 */
final class VerifyEmployeeEmail extends Notification
{
    public function __construct(private readonly string $businessName) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi email Anda di gs.POS')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Anda ditambahkan sebagai karyawan '.$this->businessName.' di gs.POS. Klik tombol di bawah untuk memverifikasi email Anda.')
            ->action('Verifikasi email', VerifyOwnerEmail::url($notifiable))
            ->line('Link berlaku '.VerifyOwnerEmail::VALID_DAYS.' hari. Abaikan email ini bila Anda tidak merasa bekerja di '.$this->businessName.'.')
            ->salutation('Salam, tim gs.POS');
    }
}
