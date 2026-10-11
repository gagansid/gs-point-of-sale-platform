<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Models\Tenant;
use App\Models\User;
use App\Support\DummyPasswordHash;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi login kasir web (Q54): email + kata sandi (owner/manager) atau kode bisnis + username +
 * kata sandi (supervisor/kasir). Tidak membuat sesi; pemanggil yang login-kan ke guard "cashier".
 */
final class LoginWebCashier
{
    public const INVALID = 'Data login salah.';

    /**
     * @param  string|null  $business  slug tenant; null = login dengan email
     *
     * @throws ValidationException
     */
    public function handle(?string $business, string $login, string $password): User
    {
        // Diambil di semua jalur agar waktu respons sama (anti enumerasi akun)
        $dummyHash = DummyPasswordHash::get();
        $user = $this->find($business, mb_strtolower(trim($login)));
        $passwordValid = Hash::check($password, $user->password ?? $dummyHash);

        if ($user === null || ! $passwordValid || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => self::INVALID]);
        }

        $this->ensureCanEnter($user);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $user;
    }

    /**
     * Syarat masuk kasir web setelah identitas terbukti. Pesan khusus hanya setelah kata sandi/PIN benar:
     * tidak membuka informasi akun.
     *
     * @throws ValidationException
     */
    public function ensureCanEnter(User $user): void
    {
        if (! $user->canUseWebCashier()) {
            throw ValidationException::withMessages(['login' => 'Akun Anda belum diberi akses kasir web. Hubungi owner.']);
        }

        if (! $user->tenant->isActive()) {
            throw ValidationException::withMessages(['login' => 'Langganan bisnis Anda tidak aktif. Hubungi tim gs.POS.']);
        }

        if ($user->accessibleOutlets()->where('is_active', true)->doesntExist()) {
            throw ValidationException::withMessages(['login' => 'Anda belum ditugaskan di outlet aktif. Hubungi owner.']);
        }
    }

    private function find(?string $business, string $login): ?User
    {
        // Lintas tenant: tenant baru diketahui dari email (unik global) atau kode bisnis
        $query = User::allTenants()->with('tenant');

        if ($business === null) {
            return $query->where('email', $login)->first();
        }

        $tenantId = Tenant::query()->where('slug', mb_strtolower(trim($business)))->value('id');

        return $tenantId === null ? null : $query->where('tenant_id', $tenantId)->where('username', $login)->first();
    }
}
