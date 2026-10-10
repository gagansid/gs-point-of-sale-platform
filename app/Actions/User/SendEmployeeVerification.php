<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Notifications\VerifyEmployeeEmail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mengirim link verifikasi ke email karyawan yang belum terverifikasi (SPEC Q46, permission user.manage).
 * Dibatasi 3 kali per 10 menit per karyawan agar tidak dipakai membanjiri kotak masuk orang lain.
 */
final class SendEmployeeVerification
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 600;

    /**
     * @return bool false bila tidak ada yang perlu dikirim (tanpa email / sudah terverifikasi)
     *
     * @throws BusinessException TOO_MANY_REQUESTS
     */
    public function handle(User $employee): bool
    {
        if ($employee->email === null || $employee->hasVerifiedEmail()) {
            return false;
        }

        $key = 'employee-verification:'.$employee->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Link verifikasi sudah dikirim beberapa kali. Coba lagi dalam '
                .(int) ceil(RateLimiter::availableIn($key) / 60).' menit.');
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
        $employee->notify(new VerifyEmployeeEmail((string) $employee->tenant->name));

        return true;
    }
}
