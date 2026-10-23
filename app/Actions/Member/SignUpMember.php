<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Member\Data\MemberData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Daftar mandiri member (ADR 0013, Q67) dari halaman member atau **daftar singkat** di halaman pesan meja (ADR 0027 K5):
 * nama, No. HP, kata sandi kuat, persetujuan data; tanpa OTP. Maks. 5 pendaftaran/jam per IP. Kuota member penuh tidak
 * membuka angka kuota ke publik. Dipanggil di dalam tenant context bisnis itu.
 */
final class SignUpMember
{
    private const PER_IP_PER_HOUR = 5;

    public function __construct(private readonly RegisterMemberAccount $register) {}

    /**
     * @param  array<string, mixed>  $input  name, phone, password, (password_confirmation), consent
     * @param  bool  $confirmPassword  halaman member meminta ulangi kata sandi; daftar singkat memakai tombol tampilkan
     *
     * @throws ValidationException
     */
    public function handle(array $input, string $ip, bool $confirmPassword = true): Member
    {
        $password = ['required', 'string', Password::min(8)->letters()->numbers()];
        if ($confirmPassword) {
            $password[] = 'confirmed';
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@]/'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => $password,
            'consent' => ['accepted'],
        ], [], ['name' => 'nama', 'phone' => 'nomor HP', 'password' => 'kata sandi', 'consent' => 'persetujuan data'])->validate();

        // Batasi pendaftaran per IP (cegah pembuatan akun massal)
        $key = 'member-register:'.$ip;
        if (RateLimiter::tooManyAttempts($key, self::PER_IP_PER_HOUR)) {
            throw ValidationException::withMessages(['phone' => 'Terlalu banyak pendaftaran. Coba lagi nanti.']);
        }
        RateLimiter::hit($key, 3600);

        try {
            return $this->register->handle(MemberData::fromArray([
                'name' => $data['name'], 'phone' => $data['phone'], 'consent' => true,
            ]), (string) $data['password']);
        } catch (BusinessException $e) {
            // Kuota member penuh (ADR 0025): jangan buka angka kuota/pemakaian bisnis ke publik
            if ($e->errorCode === ErrorCode::PlanLimitReached->value) {
                throw ValidationException::withMessages(['phone' => 'Pendaftaran member online sedang tidak tersedia. Silakan daftar lewat kasir.']);
            }

            throw ValidationException::withMessages(is_array($e->details) && $e->details !== [] ? $e->details : ['phone' => $e->getMessage()]);
        }
    }
}
