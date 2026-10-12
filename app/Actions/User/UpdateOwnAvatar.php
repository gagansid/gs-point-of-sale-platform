<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Ganti/hapus foto profil sendiri. File diunggah form ke disk public `avatars/{tenant}/` (gambar ≤ 2 MB, dipotong
 * persegi); path di luar folder tenant user ditolak. Foto lama dihapus setelah diganti/dihapus.
 */
final class UpdateOwnAvatar
{
    /** @throws BusinessException */
    public function handle(User $user, ?string $path): User
    {
        if ($path !== null && ! str_starts_with($path, self::directory($user).'/')) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Foto profil tidak valid', ['avatar_path' => ['Unggah ulang foto profil']]);
        }

        $old = $user->avatar_path;

        if ($old === $path) {
            return $user;
        }

        $user->forceFill(['avatar_path' => $path])->save();

        if ($old !== null) {
            Storage::disk('public')->delete($old);
        }

        return $user;
    }

    public static function directory(User $user): string
    {
        return 'avatars/'.$user->tenant_id;
    }
}
