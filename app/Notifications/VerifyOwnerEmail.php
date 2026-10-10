<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Link verifikasi email owner hasil daftar mandiri (ADR 0009, SPEC Q31).
 * Link bertanda tangan & berlaku 3 hari; bisa dibuka tanpa login (mis. dari HP).
 */
final class VerifyOwnerEmail extends Notification
{
    public const VALID_DAYS = 3;

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi email akun gs.POS Anda')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Terima kasih sudah mendaftar gs.POS. Klik tombol di bawah untuk memverifikasi email Anda.')
            ->line('Setelah terverifikasi, kasir bisa mulai bertransaksi.')
            ->action('Verifikasi email', self::url($notifiable))
            ->line('Link berlaku '.self::VALID_DAYS.' hari. Abaikan email ini bila Anda tidak mendaftar.')
            ->salutation('Salam, tim gs.POS');
    }

    public static function url(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addDays(self::VALID_DAYS), [
            'user' => $user->id,
            // Link lama tidak berlaku bila email diganti
            'hash' => sha1((string) $user->email),
        ]);
    }
}
