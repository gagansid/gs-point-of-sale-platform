<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pemulihan darurat: admin kehilangan aplikasi authenticator DAN recovery code.
 * Hanya bisa dijalankan dari server (SSH/cPanel Terminal), bukan dari panel.
 */
final class ResetAdminTwoFactorCommand extends Command
{
    protected $signature = 'pos:admin-reset-2fa {email : Email super admin}';

    protected $description = 'Menghapus 2FA seorang super admin agar bisa mengaturnya ulang saat login';

    public function handle(): int
    {
        $admin = Admin::query()->where('email', strtolower((string) $this->argument('email')))->first();

        if ($admin === null) {
            $this->error('Super admin tidak ditemukan');

            return self::FAILURE;
        }

        if (! $this->confirm("Hapus 2FA milik {$admin->email}?")) {
            return self::FAILURE;
        }

        $admin->saveAppAuthenticationSecret(null);
        $admin->saveAppAuthenticationRecoveryCodes(null);

        Log::warning('2FA super admin di-reset lewat CLI', ['admin_id' => $admin->id]);
        $this->info('2FA berhasil dihapus. Jika 2FA diwajibkan, admin akan diminta mengaturnya ulang saat login');

        return self::SUCCESS;
    }
}
