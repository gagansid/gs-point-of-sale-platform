<?php

declare(strict_types=1);

namespace App\Support\Installer;

/**
 * Syarat server untuk installer (ADR 0016). Wajib = instal tidak bisa lanjut; opsional = peringatan saja.
 */
final class Requirements
{
    public const MIN_PHP = '8.2.0';

    /** Ekstensi PHP wajib (sama dengan CI/deploy). */
    public const EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'zip'];

    /**
     * @return list<array{label: string, ok: bool, required: bool, detail: string}>
     */
    public static function check(?bool $secure = null): array
    {
        $secure ??= request()->isSecure();

        $items = [[
            'label' => 'PHP '.self::MIN_PHP.' atau lebih baru',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'required' => true,
            'detail' => 'Terpasang: PHP '.PHP_VERSION,
        ]];

        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension): bool => ! extension_loaded($extension)));
        $items[] = [
            'label' => 'Ekstensi PHP ('.count(self::EXTENSIONS).')',
            'ok' => $missing === [],
            'required' => true,
            'detail' => $missing === []
                ? implode(', ', self::EXTENSIONS)
                : 'Belum aktif: '.implode(', ', $missing).' — aktifkan di cPanel → Select PHP Version → Extensions',
        ];

        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache'), '.env' => app()->environmentFilePath()] as $label => $path) {
            $writable = is_writable($path);
            $items[] = [
                'label' => "Bisa ditulis: {$label}",
                'ok' => $writable,
                'required' => true,
                'detail' => $writable ? 'OK' : 'Ubah izin folder/file (mis. 755 folder, 644 file) lewat File Manager',
            ];
        }

        $items[] = [
            'label' => 'HTTPS aktif',
            'ok' => $secure,
            'required' => false,
            'detail' => $secure ? 'OK' : 'Aktifkan SSL (cPanel → SSL/TLS Status → AutoSSL). Setelah instal semua alamat memakai https',
        ];
        $symlink = function_exists('symlink') && ! self::disabled('symlink');
        $items[] = [
            'label' => 'Symlink (file publik: foto produk & profil)',
            'ok' => $symlink,
            'required' => false,
            'detail' => $symlink ? 'Aktif' : 'Nonaktif: buat link public/storage manual atau minta hosting',
        ];
        $procOpen = function_exists('proc_open') && ! self::disabled('proc_open');
        $items[] = [
            'label' => 'proc_open (backup database otomatis)',
            'ok' => $procOpen,
            'required' => false,
            'detail' => $procOpen ? 'Aktif' : 'Nonaktif: backup harian & backup sebelum deploy tidak berjalan',
        ];

        return $items;
    }

    public static function passes(): bool
    {
        foreach (self::check() as $item) {
            if ($item['required'] && ! $item['ok']) {
                return false;
            }
        }

        return true;
    }

    private static function disabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }
}
