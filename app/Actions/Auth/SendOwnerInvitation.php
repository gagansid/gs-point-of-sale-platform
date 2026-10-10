<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\InviteOwner;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;

/**
 * Kirim link "atur kata sandi" ke owner yang dibuat tim (SPEC Q34). Token di tabel undangan
 * (berlaku 3 hari), terpisah dari token lupa kata sandi.
 */
final class SendOwnerInvitation
{
    public function handle(User $owner, string $businessName): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker('invitations');
        $token = $broker->createToken($owner);

        $owner->notify(new InviteOwner($token, $businessName));
    }
}
