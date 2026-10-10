<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Undangan owner bisnis yang dibuat tim gs.POS (SPEC Q34): atur kata sandi sendiri, 3 hari.
 * Kata sandi tidak pernah dikirim sebagai teks.
 */
final class InviteOwner extends Notification
{
    public function __construct(private readonly string $token, private readonly string $businessName) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Akun gs.POS untuk '.$this->businessName.' sudah siap')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Tim gs.POS sudah menyiapkan akun untuk '.$this->businessName.'.')
            ->line('Klik tombol di bawah untuk membuat kata sandi, lalu masuk ke dashboard.')
            ->action('Atur kata sandi', route('password.reset', ['token' => $this->token, 'email' => $notifiable->email, 'invite' => 1]))
            ->line('Link berlaku 3 hari. Setelah itu, gunakan "Lupa kata sandi" di halaman masuk.')
            ->salutation('Salam, tim gs.POS');
    }
}
