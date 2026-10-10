<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link lupa kata sandi (gspos.id/forgot-password), berlaku 60 menit.
 */
final class ResetUserPassword extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Atur ulang kata sandi gs.POS')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Kami menerima permintaan untuk mengatur ulang kata sandi akun Anda.')
            ->action('Atur ulang kata sandi', route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('Link berlaku '.config('auth.passwords.users.expire').' menit. Abaikan email ini bila Anda tidak memintanya — kata sandi tidak berubah.')
            ->salutation('Salam, tim gs.POS');
    }
}
