<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Auth\VerifyPin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Buka kunci PIN sebelum 15 menit berlalu (mis. kasir salah ketik 5 kali di awal shift): kunci global
 * dan kunci per perangkat/approval (VerifyPin).
 */
final class UnlockEmployeePin
{
    public function handle(User $employee): User
    {
        DB::transaction(fn () => VerifyPin::resetFor($employee));

        return $employee;
    }
}
