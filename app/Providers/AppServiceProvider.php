<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureSecurity();
        $this->configureRateLimiting();
    }

    private function configureModels(): void
    {
        // Di luar production: gagal keras untuk lazy loading (N+1), atribut yang diam-diam
        // dibuang karena tidak ada di $fillable, dan akses atribut yang tidak ada
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function configureSecurity(): void
    {
        // Semua URL yang dibuat aplikasi memakai https di production
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Cegah migrate:fresh, db:wipe, dsb. tidak sengaja dijalankan di production
        DB::prohibitDestructiveCommands($this->app->isProduction());

        Password::defaults(function (): Password {
            $rule = Password::min(8)->letters()->numbers();

            // Cek kebocoran (Have I Been Pwned, k-anonymity) hanya di production
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    private function configureRateLimiting(): void
    {
        // API umum: per token, fallback per IP untuk request tanpa token
        RateLimiter::for('api', function (Request $request): Limit {
            // Session (bukan token) menghasilkan TransientToken, sehingga dicek tipenya
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute((int) config('pos.rate_limits.api'))
                ->by($token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'ip:'.$request->ip());
        });

        // Login & PIN: 5/menit per IP + device (SPEC Keamanan). Batas kedua per IP saja
        // mencegah penyerang mengganti-ganti device_uid untuk brute force PIN.
        RateLimiter::for('auth', function (Request $request): array {
            $device = (string) ($request->input('device_uid') ?? $request->header('X-Device-Uid', ''));

            return [
                Limit::perMinute((int) config('pos.rate_limits.auth'))->by('auth:'.$request->ip().'|'.$device),
                Limit::perMinute((int) config('pos.rate_limits.auth') * 4)->by('auth-ip:'.$request->ip()),
            ];
        });
    }
}
