<?php

declare(strict_types=1);

namespace App\Support\Installer;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Status instalasi aplikasi (ADR 0016).
 *
 *   terinstal      file kunci storage/app/installed.json ada → /install 404 selamanya
 *   sedang instal  file status storage/app/install-state.json ada (dibuat saat /install pertama dibuka)
 *   belum          tanpa keduanya dan tanpa APP_KEY (paket rilis baru diekstrak)
 *
 * Instalasi lama/manual (sudah ada APP_KEY, tanpa file status) dianggap terinstal dan file kunci dibuat
 * otomatis — sehingga database yang sedang tidak bisa dihubungi tidak pernah membuka installer.
 *
 * Kode instal selalu wajib (GSPOS-SEC-026): INSTALL_TOKEN bila diisi, selain itu kode acak yang ditulis ke
 * storage/app/install-code.txt — hanya pemegang akses server (File Manager cPanel) yang bisa membacanya.
 */
final class Installation
{
    public static function enabled(): bool
    {
        return (bool) config('pos.installer.enabled');
    }

    public static function isInstalled(): bool
    {
        if (! self::enabled() || is_file(self::lockPath())) {
            return true;
        }

        if (self::inProgress()) {
            return false;
        }

        if (filled(config('app.key'))) {
            self::markInstalled(['source' => 'existing']);

            return true;
        }

        return false;
    }

    public static function inProgress(): bool
    {
        return is_file(self::statePath());
    }

    /**
     * Persiapan request sebelum terinstal: .env + APP_KEY dibuat bila belum ada, session & cache memakai
     * file (tabel database belum ada), mode production tanpa debug.
     */
    public static function prepareRuntime(Request $request): void
    {
        $env = new EnvironmentFile(app()->environmentFilePath());
        $url = $request->getSchemeAndHttpHost();

        // Awal instal: .env dari template (atau dilengkapi bila hanya berisi INSTALL_TOKEN), mode production
        if (! self::inProgress()) {
            $env->createFrom(base_path('.env.example'));
            $env->fillMissingFrom(base_path('.env.example'));
            $env->set([
                'APP_ENV' => 'production',
                'APP_DEBUG' => false,
                'LOG_LEVEL' => 'warning',
                'APP_URL' => $url,
            ]);
        }

        $key = $env->get('APP_KEY');

        if (blank($key)) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $env->set(['APP_KEY' => $key]);
        }

        if (! self::inProgress()) {
            self::saveState(['started_at' => now()->toIso8601String(), 'completed' => []]);
        }

        self::ensureCode();

        config([
            'app.key' => $key,
            'app.debug' => false,
            // URL sementara = host yang sedang dipakai (TrustHosts); URL final ditulis saat instal
            'app.url' => $url,
            'session.driver' => 'file',
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);

        // Production memaksa https (AppServiceProvider); selama instal ikut skema request agar hosting yang
        // SSL-nya belum aktif tetap bisa dipakai. Setelah terinstal kembali https.
        URL::forceScheme($request->getScheme());

        // Encrypter mungkin sudah dibuat dengan key kosong; rate limiter & cache sudah terikat ke store database
        // sejak boot (RateLimiter::for di AppServiceProvider) — dibuat ulang dengan store file
        app()->forgetInstance('encrypter');
        app('cache')->forgetDriver();
        app()->forgetInstance(RateLimiter::class);
    }

    /**
     * @return array{started_at?: string, completed: list<string>}
     */
    public static function state(): array
    {
        $raw = is_file(self::statePath()) ? json_decode((string) file_get_contents(self::statePath()), true) : null;
        $state = is_array($raw) ? $raw : [];
        $state['completed'] = array_values(array_filter((array) ($state['completed'] ?? []), is_string(...)));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveState(array $state): void
    {
        self::writeJson(self::statePath(), $state);
    }

    public static function completeStep(string $step): void
    {
        $state = self::state();
        $state['completed'] = array_values(array_unique([...$state['completed'], $step]));
        self::saveState($state);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function markInstalled(array $meta = []): void
    {
        self::writeJson(self::lockPath(), [
            'installed_at' => now()->toIso8601String(),
            'version' => (string) config('app.version', ''),
            ...$meta,
        ]);

        foreach ([self::statePath(), self::codePath()] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Kode instal yang berlaku: INSTALL_TOKEN, atau kode acak di file server; null = belum dibuat. */
    public static function token(): ?string
    {
        $token = config('pos.installer.token');

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $code = is_file(self::codePath()) ? trim((string) file_get_contents(self::codePath())) : '';

        return $code !== '' ? $code : null;
    }

    /** true = kode dari INSTALL_TOKEN, false = kode acak di storage/app/install-code.txt. */
    public static function tokenFromEnvironment(): bool
    {
        $token = config('pos.installer.token');

        return is_string($token) && $token !== '';
    }

    /** Gagal tertutup: tanpa kode yang berlaku, tidak ada isian yang diterima. */
    public static function tokenMatches(string $input): bool
    {
        $token = self::token();
        $input = Str::of($input)->trim()->toString();

        if ($token === null || $input === '') {
            return false;
        }

        // Kode acak tidak peka huruf besar/kecil; INSTALL_TOKEN dibandingkan apa adanya
        return self::tokenFromEnvironment()
            ? hash_equals($token, $input)
            : hash_equals($token, Str::upper($input));
    }

    /** Path kode instal relatif terhadap folder aplikasi, untuk ditampilkan di form. */
    public static function codeDisplayPath(): string
    {
        return ltrim(Str::after(self::codePath(), base_path()), '/');
    }

    /**
     * Membuat kode acak 12 karakter (±60 bit, tanpa huruf/angka yang mirip) bila INSTALL_TOKEN kosong
     * dan file kode belum ada. Gagal menulis = RuntimeException (halaman "folder tidak bisa ditulis").
     */
    private static function ensureCode(): void
    {
        if (self::tokenFromEnvironment() || is_file(self::codePath())) {
            return;
        }

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $chars = '';

        for ($i = 0; $i < 12; $i++) {
            $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $dir = dirname(self::codePath());

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (@file_put_contents(self::codePath(), implode('-', str_split($chars, 4))."\n", LOCK_EX) === false) {
            throw new RuntimeException('Tidak bisa membuat kode instal di '.self::codeDisplayPath().'. Pastikan folder storage bisa ditulis oleh PHP.');
        }

        @chmod(self::codePath(), 0600);
    }

    private static function lockPath(): string
    {
        return (string) config('pos.installer.lock_path');
    }

    private static function statePath(): string
    {
        return (string) config('pos.installer.state_path');
    }

    private static function codePath(): string
    {
        return (string) config('pos.installer.code_path');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
