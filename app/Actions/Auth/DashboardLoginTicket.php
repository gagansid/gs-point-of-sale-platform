<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tiket sekali pakai untuk login dari gspos.id/login ke app.gspos.id (ADR 0008).
 *
 * Cookie session tiap subdomain sengaja host-only (sesi app. tidak terbawa ke admin.), sehingga
 * login di domain utama tidak bisa langsung berlaku di app.. Domain utama memverifikasi kata sandi,
 * lalu membuat tiket acak 64 karakter yang hanya disimpan sebagai hash, berlaku 60 detik, dan
 * dihapus saat pertama kali ditukar.
 */
final class DashboardLoginTicket
{
    private const TTL_SECONDS = 60;

    public function issue(User $user, bool $remember, ?string $next): string
    {
        $ticket = Str::random(64);

        Cache::put(self::key($ticket), [
            'user_id' => $user->id,
            'remember' => $remember,
            'next' => $next,
        ], self::TTL_SECONDS);

        return $ticket;
    }

    /**
     * @return array{user: User, remember: bool, next: string|null}|null null = tidak ada/kedaluwarsa/sudah dipakai
     */
    public function redeem(string $ticket): ?array
    {
        if (strlen($ticket) !== 64) {
            return null;
        }

        // pull = ambil lalu hapus: tiket yang sama tidak bisa dipakai dua kali
        $payload = Cache::pull(self::key($ticket));

        if (! is_array($payload) || ! is_string($payload['user_id'] ?? null)) {
            return null;
        }

        // Lintas tenant: tenant baru diketahui dari user; id berasal dari tiket di server, bukan input
        $user = User::allTenants()->find($payload['user_id']);

        if (! $user instanceof User) {
            return null;
        }

        return [
            'user' => $user,
            'remember' => (bool) ($payload['remember'] ?? false),
            'next' => self::safePath($payload['next'] ?? null),
        ];
    }

    /**
     * Hanya path relatif di app. (mis. "/products?page=2"); URL absolut, "//host", atau "\\" ditolak
     * agar parameter next tidak bisa dipakai untuk open redirect.
     */
    public static function safePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 500) {
            return null;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $path) === 1 ? null : $path;
    }

    private static function key(string $ticket): string
    {
        return 'dashboard-login-ticket:'.hash('sha256', $ticket);
    }
}
