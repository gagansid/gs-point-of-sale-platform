<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\User\UpdateOwnProfile;
use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Profil owner/manager. Penyimpanan lewat Action (bukan $record->update bawaan Filament) agar
 * aturan email terverifikasi & pencabutan token berlaku (GSPOS-SEC-012, GSPOS-SEC-013).
 */
class EditProfile extends BaseEditProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        /** @var User $record */
        return app(UpdateOwnProfile::class)->handle(
            $record,
            (string) $data['name'],
            (string) $data['email'],
            isset($data['password']) ? (string) $data['password'] : null,
        );
    }
}
