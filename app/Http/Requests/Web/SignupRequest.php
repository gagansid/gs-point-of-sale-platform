<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\BusinessType;
use App\Rules\TurnstilePassed;
use App\Services\Security\Turnstile;
use App\Support\SystemSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class SignupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:100'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'name' => ['required', 'string', 'max:100'],
            // Email unik global (login email lintas tenant), termasuk akun yang dihapus lunak
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email'), $this->allowedDomain(...)],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
            // Honeypot: field tersembunyi, hanya diisi bot
            'website' => ['nullable', 'string', 'max:255'],
            // Cloudflare Turnstile mengikuti setelan halaman owner (login, daftar, lupa kata sandi)
            Turnstile::FIELD => [new TurnstilePassed(Turnstile::OWNER)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'business_name' => 'nama bisnis',
            'business_type' => 'jenis usaha',
            'name' => 'nama Anda',
            'email' => 'email',
            'password' => 'kata sandi',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'Email ini sudah terdaftar. Silakan masuk atau gunakan email lain.'];
    }

    /** Domain email yang diblokir admin (Pengaturan → Pendaftaran), termasuk subdomainnya. */
    private function allowedDomain(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = mb_strtolower((string) str((string) $value)->afterLast('@'));

        foreach (app(SystemSettings::class)->signupBlockedDomains() as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                $fail('Email dengan domain ini tidak bisa dipakai untuk mendaftar. Gunakan email lain.');

                return;
            }
        }
    }

    public function isBot(): bool
    {
        return filled($this->input('website'));
    }
}
