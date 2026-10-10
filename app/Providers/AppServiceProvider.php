<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\TenantContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: direset setiap request & job, sehingga tenant tidak bocor antar request
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        $this->configureTenancy();
        $this->configureAuthorization();
        $this->configureModels();
        $this->configureSecurity();
        $this->configureRateLimiting();
        $this->configureApiDocs();
    }

    private function configureApiDocs(): void
    {
        // Dokumentasi OpenAPI (/docs/api) hanya untuk local & staging, tidak pernah di production
        Gate::define('viewApiDocs', fn (mixed $user = null): bool => $this->app->environment(['local', 'staging']));

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }

    private function configureTenancy(): void
    {
        // Job queue yang dikirim dari request tenant berjalan di tenant yang sama
        Context::hydrated(function (ContextRepository $context): void {
            $tenantId = $context->get('tenant_id');

            if (is_string($tenantId) && $tenantId !== '') {
                TenantContext::set($tenantId);
            }
        });
    }

    private function configureAuthorization(): void
    {
        Auth::provider('tenant_users', fn (Application $app, array $config): TenantUserProvider => new TenantUserProvider(
            $app['hash'],
            $config['model'],
        ));

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Catat waktu login terakhir (audit sederhana) tanpa memicu event model lain
        Event::listen(function (Login $event): void {
            if ($event->user instanceof Admin || $event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // Permission (order.void, ...) diputuskan HANYA oleh role. Ability lain (view, update, ...)
        // diteruskan ke Policy agar aturan bisnis di Policy tidak dilewati owner (ADR 0005).
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User || ! UserRole::isPermission($ability)) {
                return null;
            }

            // Data rusak (role kosong/tidak dikenal) = ditolak, bukan error
            $role = $user->getAttribute('role');

            return $role instanceof UserRole && $role->allows($ability);
        });
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

        // Form hubungi sales di halaman depan: 3/menit & 10/jam per IP (anti-spam)
        RateLimiter::for('contact', fn (Request $request): array => [
            Limit::perMinute(3)->by('contact-m:'.$request->ip()),
            Limit::perHour(10)->by('contact-h:'.$request->ip()),
        ]);

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
