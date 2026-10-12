<?php

declare(strict_types=1);

namespace App\Support\Installer;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Status instalasi aplikasi (ADR 0016).
 *
 *   terinstal      file kunci storage/app/installed.json ada → /install 404 selamanya
 *   sedang instal  file status storage/app/install-state.json ada (dibuat saat /install pertama dibuka)
 *   belum          tanpa keduanya dan tanpa APP_KEY (paket rilis baru diekstrak)
 *
 * Instalasi lama/manual (sudah ada APP_KEY, tanpa file status) dianggap terinstal dan file kunci dibuat
 * otomatis — sehingga database yang sedang tidak bisa dihubungi tidak pernah membuka installer.
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

        if (is_file(self::statePath())) {
            @unlink(self::statePath());
        }
    }

    /** Kode instal opsional (INSTALL_TOKEN); null = installer terbuka tanpa kode. */
    public static function token(): ?string
    {
        $token = config('pos.installer.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function tokenMatches(string $input): bool
    {
        $token = self::token();

        return $token === null || hash_equals($token, Str::of($input)->trim()->toString());
    }

    private static function lockPath(): string
    {
        return (string) config('pos.installer.lock_path');
    }

    private static function statePath(): string
    {
        return (string) config('pos.installer.state_path');
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
