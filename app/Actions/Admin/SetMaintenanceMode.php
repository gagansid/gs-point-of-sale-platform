<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Sistem → Maintenance: `php artisan down` dengan secret bypass (SPEC panel admin). Selama aktif, semua
 * halaman & API membalas 503 (API: MAINTENANCE); admin yang membuka URL secret mendapat cookie bypass.
 */
final class SetMaintenanceMode
{
    /** @return string secret bypass (dipakai sebagai path: https://admin.gspos.id/{secret}) */
    public function enable(): string
    {
        $secret = Str::random(40);

        Artisan::call('down', ['--secret' => $secret, '--retry' => 60]);

        return $secret;
    }

    public function disable(): void
    {
        Artisan::call('up');
    }

    public function isEnabled(): bool
    {
        return app()->isDownForMaintenance();
    }

    /** Secret yang sedang berlaku (null bila tidak maintenance). */
    public function secret(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $secret = app()->maintenanceMode()->data()['secret'] ?? null;

        return is_string($secret) ? $secret : null;
    }
}
