<?php

declare(strict_types=1);

namespace App\Actions\Install;

use App\Actions\Install\Data\InstallData;
use App\Support\Installer\EnvironmentFile;

/**
 * Langkah instal 1 (ADR 0016): menulis konfigurasi final ke .env (database, URL, edisi, domain, email).
 * APP_KEY yang dibuat saat /install pertama dibuka tetap dipakai (session installer tetap sah).
 */
final class WriteInstallEnvironment
{
    public function handle(InstallData $data): void
    {
        $https = str_starts_with($data->appUrl, 'https://');
        $mail = $data->mailHost !== null;

        $env = new EnvironmentFile(app()->environmentFilePath());
        $env->set([
            'APP_NAME' => $data->appName,
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'APP_URL' => $data->appUrl,
            'APP_TIMEZONE' => 'UTC',
            'LOG_LEVEL' => 'warning',

            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data->database['host'],
            'DB_PORT' => (string) $data->database['port'],
            'DB_DATABASE' => $data->database['database'],
            'DB_USERNAME' => $data->database['username'],
            'DB_PASSWORD' => $data->database['password'],

            'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => $https,
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',

            'POS_EDITION' => $data->edition,
            'POS_DEFAULT_TIMEZONE' => $data->timezone,
            'POS_MAIN_DOMAIN' => null,
            'POS_APP_DOMAIN' => $data->appDomain,
            'POS_ADMIN_DOMAIN' => $data->adminDomain,
            'POS_API_DOMAIN' => $data->apiDomain,

            // Tanpa SMTP: email dicatat di log (bisa diisi nanti di .env)
            'MAIL_MAILER' => $mail ? 'smtp' : 'log',
            'MAIL_HOST' => $mail ? $data->mailHost : '127.0.0.1',
            'MAIL_PORT' => $mail ? (string) ($data->mailPort ?? 587) : '2525',
            'MAIL_USERNAME' => $mail ? $data->mailUsername : null,
            'MAIL_PASSWORD' => $mail ? $data->mailPassword : null,
            'MAIL_SCHEME' => $mail && $data->mailEncryption === 'ssl' ? 'smtps' : null,
            'MAIL_FROM_ADDRESS' => $data->mailFromAddress ?? self::defaultFromAddress($data->appUrl),
            'MAIL_FROM_NAME' => $data->appName,
        ]);
    }

    /**
     * no-reply@{domain}; host berupa IP/tanpa titik tidak sah sebagai email (dan membuat konfigurasi backup gagal
     * saat boot), jadi diganti alamat contoh sampai SMTP diatur.
     */
    private static function defaultFromAddress(string $appUrl): string
    {
        $host = (string) parse_url($appUrl, PHP_URL_HOST);
        $candidate = 'no-reply@'.$host;

        return filter_var($host, FILTER_VALIDATE_IP) === false && str_contains($host, '.') && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
            ? $candidate
            : 'no-reply@example.com';
    }
}
