<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Filament\Shared\Auth\Login as SharedLogin;
use App\Services\Security\Turnstile;

/** Login super admin (/admin): tampilan halaman masuk bersama + Turnstile "Login super admin". */
final class Login extends SharedLogin
{
    public static function turnstileAudience(): string
    {
        return Turnstile::ADMIN;
    }

    public function authBrand(): array
    {
        return [
            'subheading' => 'Panel super admin gs.POS',
            'app' => 'Super Admin',
            'heading' => 'Pusat kendali platform gs.POS',
            'text' => 'Kelola bisnis pelanggan, paket langganan, tagihan, dan kesehatan sistem.',
            'points' => [['users', 'Bisnis & calon pelanggan'], ['receipt', 'Paket, add-on, dan tagihan'], ['shield', 'Keamanan & verifikasi dua langkah']],
        ];
    }
}
