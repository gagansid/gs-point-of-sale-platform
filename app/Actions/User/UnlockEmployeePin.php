<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Buka kunci PIN sebelum 15 menit berlalu (mis. kasir salah ketik 5 kali di awal shift).
 */
final class UnlockEmployeePin
{
    public function handle(User $employee): User
    {
        DB::transaction(fn () => $employee->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save());

        return $employee;
    }
}
