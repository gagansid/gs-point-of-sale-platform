<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keluar dari semua perangkat lain (halaman Profil): sesi dashboard lain (tabel sessions, driver database) dihapus
 * dan semua token API (aplikasi kasir/owner) dicabut. Sesi browser saat ini tetap.
 */
final class LogoutOtherSessions
{
    /** @return array{sessions: int, tokens: int} */
    public function handle(User $user, string $currentSessionId): array
    {
        return DB::transaction(fn (): array => [
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->delete(),
            'tokens' => $user->tokens()->delete(),
        ]);
    }
}
