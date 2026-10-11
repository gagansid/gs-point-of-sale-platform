<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Member\Data\MemberData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Daftar mandiri di halaman member (ADR 0013): data + persetujuan + kata sandi. Nomor yang sudah terdaftar (mis.
 * didaftarkan kasir) tidak bisa diklaim dari sini — kasir/owner yang mengatur kata sandi sementara.
 */
final class RegisterMemberAccount
{
    public function __construct(private readonly SaveMember $save) {}

    /** @throws BusinessException VALIDATION_ERROR */
    public function handle(MemberData $data, #[SensitiveParameter] string $password): Member
    {
        $phone = Phone::normalize($data->phone);

        if ($phone !== null && Member::query()->where('phone', $phone)->exists()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nomor HP sudah terdaftar', [
                'phone' => ['Nomor ini sudah terdaftar. Masuk, atau minta kasir mengatur kata sandi Anda'],
            ]);
        }

        return DB::transaction(function () use ($data, $password): Member {
            $member = $this->save->handle(null, $data)['member'];
            $member->forceFill(['password' => $password, 'must_change_password' => false])->save();

            return $member;
        });
    }
}
