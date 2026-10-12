<?php

declare(strict_types=1);

namespace App\Actions\Install;

use App\Support\Domains;
use App\Support\Edition;
use App\Support\Installer\Installation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Langkah instal 4 (ADR 0016): link file publik, kunci installer, dan URL login tujuan.
 * Dengan bisnis → login owner; tanpa bisnis (SaaS) → login super admin.
 */
final class FinishInstallation
{
    /**
     * @return array{redirect: string, warning: string|null}
     */
    public function handle(bool $hasBusiness): array
    {
        $warning = null;

        if (! file_exists(public_path('storage'))) {
            try {
                Artisan::call('storage:link');
            } catch (Throwable $e) {
                report($e);
                $warning = 'Link public/storage gagal dibuat (symlink nonaktif). Foto produk & profil belum tampil sampai link dibuat.';
            }
        }

        Installation::markInstalled(['source' => 'installer', 'edition' => Edition::current()]);

        $redirect = $hasBusiness
            ? (Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getLoginUrl())
            : Filament::getPanel('admin')->getLoginUrl();

        return ['redirect' => $redirect, 'warning' => $warning];
    }
}
