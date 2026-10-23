<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\RotateAppKey;
use App\Support\Installer\EnvironmentFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mengganti APP_KEY & mengenkripsi ulang data rahasia (GSPOS-SEC-025). Jalankan sekali setelah deploy pertama
 * (paket hosting membawa key lokal) dan setiap kali key diduga bocor. Hanya dari server (SSH/cPanel Terminal).
 */
final class RotateAppKeyCommand extends Command
{
    protected $signature = 'pos:rotate-key
        {--keep-previous : Simpan key lama di APP_PREVIOUS_KEYS (link verifikasi email lama tetap berlaku; hapus manual nanti)}
        {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Mengganti APP_KEY, mengenkripsi ulang data rahasia, dan memutus semua session web';

    public function handle(RotateAppKey $rotate): int
    {
        $this->warn('Semua pengguna web (dashboard, admin, kasir web, member) akan keluar dan harus masuk ulang.');
        $this->line('Token aplikasi kasir (Flutter) tidak terpengaruh.');

        if (! $this->option('force') && ! $this->confirm('Ganti APP_KEY sekarang?')) {
            return self::FAILURE;
        }

        try {
            $result = $rotate->handle(new EnvironmentFile(app()->environmentFilePath()), (bool) $this->option('keep-previous'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("APP_KEY diganti. {$result['reencrypted']} data rahasia dienkripsi ulang, {$result['sessions']} session dihapus.");

        if ($result['unreadable'] !== []) {
            // Sudah tidak terbaca sebelum rotasi (key lama hilang): isi ulang dari panel
            $this->warn('Tidak terbaca & dibiarkan (isi ulang dari panel): '.implode(', ', $result['unreadable']));
        }

        if ($this->option('keep-previous')) {
            $this->warn('Key lama masih berlaku di APP_PREVIOUS_KEYS. Kosongkan setelah link verifikasi lama tidak dibutuhkan.');
        }

        $this->line('Bila server memakai cache konfigurasi, jalankan: php artisan optimize');

        return self::SUCCESS;
    }
}
