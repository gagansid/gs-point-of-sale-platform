<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Kata sandi halaman member (ADR 0013). Member mengganti sendiri, atau kasir/owner membuat kata sandi sementara
 * (wajib diganti saat masuk berikutnya) — pengganti "lupa kata sandi" karena tanpa email/OTP.
 */
final class ChangeMemberPassword
{
    public function handle(Member $member, #[SensitiveParameter] string $password): Member
    {
        DB::transaction(fn () => $member->forceFill(['password' => $password, 'must_change_password' => false])->save());

        return $member;
    }

    /** @return string kata sandi sementara (ditampilkan sekali ke kasir/owner) */
    public function temporary(Member $member): string
    {
        // Tanpa huruf/angka mirip agar mudah dibacakan
        $password = Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
        $password = strtr($password, ['O' => 'P', 'I' => 'K', 'L' => 'M', '0' => '7', '1' => '8']);

        DB::transaction(fn () => $member->forceFill(['password' => $password, 'must_change_password' => true])->save());

        return $password;
    }
}
