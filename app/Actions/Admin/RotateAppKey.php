<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\SystemSetting;
use App\Support\Installer\EnvironmentFile;
use App\Support\SystemSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mengganti APP_KEY tanpa kehilangan data terenkripsi (GSPOS-SEC-025). Dipakai sekali setelah deploy paket yang
 * membawa key lokal, atau segera bila key diduga bocor (key bocor = cookie, session, signed URL & snapshot Livewire
 * bisa dipalsukan). Hanya lewat CLI `pos:rotate-key`.
 *
 * Urutan dibuat aman bila terhenti di tengah dan bisa diulang:
 *   1. .env: APP_KEY = key baru, APP_PREVIOUS_KEYS = key lama → aplikasi tetap bisa membaca data lama
 *   2. data terenkripsi dienkripsi ulang dengan key baru (satu transaksi); nilai yang sudah tidak terbaca dibiarkan
 *   3. semua session web dihapus (pengguna masuk ulang); token API aplikasi Flutter tidak terpengaruh
 *   4. APP_PREVIOUS_KEYS dikosongkan (kecuali $keepPrevious) → key lama tidak berlaku lagi
 *
 * Lintas tenant (payment_gateway_settings semua bisnis) disengaja: operasi platform dari server, bukan dari panel.
 */
final class RotateAppKey
{
    /** Kolom terenkripsi (cast `encrypted` / `encrypted:array` = Crypt::encryptString) per tabel. */
    public const ENCRYPTED_COLUMNS = [
        'payment_gateway_settings' => ['server_key', 'client_key'],
        'admins' => ['app_authentication_secret', 'app_authentication_recovery_codes'],
    ];

    /**
     * @return array{reencrypted: int, unreadable: list<string>, sessions: int}
     *
     * @throws RuntimeException .env tidak ada / tidak bisa ditulis
     */
    public function handle(EnvironmentFile $env, bool $keepPrevious = false): array
    {
        if (! $env->exists()) {
            throw new RuntimeException('File .env tidak ditemukan');
        }

        $cipher = (string) config('app.cipher');
        $current = (string) config('app.key');
        $previous = array_values(array_filter((array) config('app.previous_keys'), fn (mixed $k): bool => is_string($k) && $k !== ''));

        if ($current === '') {
            throw new RuntimeException('APP_KEY belum diatur');
        }

        $newKey = 'base64:'.base64_encode(Encrypter::generateKey($cipher));
        $oldKeys = array_values(array_unique([$current, ...$previous]));

        // 1. Key baru berlaku, key lama tetap bisa membaca selama data dienkripsi ulang
        $env->set(['APP_KEY' => $newKey, 'APP_PREVIOUS_KEYS' => implode(',', $oldKeys)]);
        $this->useKeys($newKey, $oldKeys);

        // 2. Enkripsi ulang: pembaca mengenali key baru & lama, penulis selalu key baru
        $encrypter = (new Encrypter(self::keyBytes($newKey), $cipher))
            ->previousKeys(array_map(self::keyBytes(...), $oldKeys));
        $result = DB::transaction(fn (): array => $this->reencrypt($encrypter));

        // 3. Session lama dibuat dengan key lama: semua pengguna web masuk ulang
        $sessions = $this->clearSessions();

        // 4. Key lama dicabut
        if (! $keepPrevious) {
            $env->set(['APP_PREVIOUS_KEYS' => '']);
            $this->useKeys($newKey, []);
        }

        Log::warning('APP_KEY diganti lewat CLI', [
            'reencrypted' => $result['reencrypted'],
            'unreadable' => $result['unreadable'],
            'sessions_cleared' => $sessions,
            'previous_kept' => $keepPrevious,
        ]);

        return [...$result, 'sessions' => $sessions];
    }

    /**
     * @return array{reencrypted: int, unreadable: list<string>}
     */
    private function reencrypt(Encrypter $encrypter): array
    {
        $count = 0;
        $unreadable = [];

        // Ditulis lewat query builder agar cast model tidak mengenkripsi dengan key yang sedang berlaku
        foreach (self::ENCRYPTED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])
                    ->chunk(200, function ($rows) use ($table, $column, $encrypter, &$count, &$unreadable): void {
                        foreach ($rows as $row) {
                            $value = self::rotate($encrypter, (string) $row->{$column});

                            if ($value === null) {
                                $unreadable[] = "{$table}.{$column}#{$row->id}";

                                continue;
                            }

                            DB::table($table)->where('id', $row->id)->update([$column => $value]);
                            $count++;
                        }
                    });
            }
        }

        // Setelan platform: kolom value ber-cast json, jadi lewat model
        foreach (SystemSettings::encryptedKeys() as $key) {
            $setting = SystemSetting::query()->find($key);

            if ($setting === null || ! is_string($setting->value) || $setting->value === '') {
                continue;
            }

            $value = self::rotate($encrypter, $setting->value);

            if ($value === null) {
                $unreadable[] = "system_settings.{$key}";

                continue;
            }

            $setting->value = $value;
            $setting->save();
            SystemSettings::forgetCached($key);
            $count++;
        }

        return ['reencrypted' => $count, 'unreadable' => $unreadable];
    }

    /** Payload terenkripsi → payload dengan key baru; null = tidak terbaca dengan key mana pun. */
    private static function rotate(Encrypter $encrypter, string $payload): ?string
    {
        try {
            return $encrypter->encryptString($encrypter->decryptString($payload));
        } catch (DecryptException) {
            return null;
        }
    }

    private function clearSessions(): int
    {
        if (config('session.driver') === 'database') {
            return DB::table((string) config('session.table', 'sessions'))->delete();
        }

        if (config('session.driver') === 'file') {
            // File tersembunyi (.gitignore) dibiarkan
            $files = array_filter(File::files((string) config('session.files')), fn ($file): bool => ! str_starts_with($file->getFilename(), '.'));
            File::delete(array_map(fn ($file): string => $file->getPathname(), $files));

            return count($files);
        }

        return 0;
    }

    /**
     * Proses ini (dan config cache) memakai key baru: encrypter dibuat ulang; config cache dikosongkan agar request web
     * membaca .env yang baru. Jalankan `php artisan optimize` lagi setelahnya bila server memakai cache.
     *
     * @param  list<string>  $previous
     */
    private function useKeys(string $key, array $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }
    }

    private static function keyBytes(string $key): string
    {
        return Str::startsWith($key, 'base64:') ? (string) base64_decode(Str::after($key, 'base64:'), true) : $key;
    }
}
