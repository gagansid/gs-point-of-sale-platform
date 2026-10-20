<?php

declare(strict_types=1);

namespace App\Filament\Shared\Auth;

use App\Services\Security\Turnstile;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * Login panel Filament bergaya halaman masuk lain (x-auth.layout): latar navy + panel brand, field 44px berikon,
 * tombol mata tanpa border, versi aplikasi, dan Cloudflare Turnstile (on/off per halaman di Sistem → Keamanan).
 * Turnstile hanya diperiksa di langkah kata sandi; langkah kode 2FA tidak meminta verifikasi ulang.
 */
abstract class Login extends BaseLogin
{
    protected static string $layout = 'filament.shared.auth-layout';

    /** Turnstile::ADMIN / Turnstile::OWNER */
    abstract public static function turnstileAudience(): string;

    /**
     * Isi panel brand di kiri & label versi.
     *
     * @return array{app: string, subheading?: string, heading: string, text: string, points: list<array{0: string, 1: string}>}
     */
    abstract public function authBrand(): array;

    public function getSubheading(): string|Htmlable|null
    {
        return $this->authBrand()['subheading'] ?? null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
            $this->getTurnstileFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getEmailFormComponent();

        return $component->prefixIcon(Heroicon::OutlinedEnvelope)->placeholder('nama@email.com');
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        return $component->prefixIcon(Heroicon::OutlinedLockClosed)->placeholder('Kata sandi')->revealable();
    }

    protected function getTurnstileFormComponent(): Component
    {
        return ViewField::make('turnstile')->hiddenLabel()
            ->view('filament.shared.turnstile-field')
            ->viewData(['siteKey' => Turnstile::siteKey()])
            ->visible(fn (): bool => app(Turnstile::class)->enabled(static::turnstileAudience()));
    }

    public function authenticate(): ?LoginResponse
    {
        $turnstile = app(Turnstile::class);
        $checkTurnstile = blank($this->userUndertakingMultiFactorAuthentication) && $turnstile->enabled(static::turnstileAudience());

        if ($checkTurnstile && ! $turnstile->verify($this->data['turnstile'] ?? null, request()->ip())) {
            $this->resetTurnstile();

            throw ValidationException::withMessages(['data.turnstile' => 'Verifikasi keamanan gagal. Centang ulang kotak verifikasi lalu coba lagi.']);
        }

        try {
            return parent::authenticate();
        } catch (ValidationException $e) {
            // Token Turnstile sekali pakai: minta widget membuat token baru untuk percobaan berikutnya
            if ($checkTurnstile) {
                $this->resetTurnstile();
            }

            throw $e;
        }
    }

    private function resetTurnstile(): void
    {
        $this->data['turnstile'] = null;
        $this->dispatch('turnstile-reset');
    }
}
