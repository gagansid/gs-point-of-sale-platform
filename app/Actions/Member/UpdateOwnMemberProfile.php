<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Member\Data\MemberData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;

/**
 * Member mengubah profil sendiri di halaman member (`/m/{bisnis}/profile`).
 *
 * - Nomor HP tidak bisa diganti sendiri (identitas login & kasir).
 * - Tanggal lahir hanya bisa diisi sekali; setelah terisi hanya kasir/owner yang mengubahnya (GSPOS-SEC-028),
 *   sehingga voucher ulang tahun tidak bisa didapat tiap bulan dengan memindah bulan lahir.
 */
final class UpdateOwnMemberProfile
{
    public function __construct(private readonly SaveMember $save) {}

    /**
     * @param  array{name: string, email?: string|null, birth_day?: int|string|null, birth_month?: int|string|null}  $data
     *
     * @throws BusinessException VALIDATION_ERROR (birth_day: sudah terisi / tidak valid)
     */
    public function handle(Member $member, array $data): Member
    {
        $birthDay = filled($data['birth_day'] ?? null) ? (int) $data['birth_day'] : null;
        $birthMonth = filled($data['birth_month'] ?? null) ? (int) $data['birth_month'] : null;

        if (self::birthDateLocked($member)) {
            if (($birthDay !== null || $birthMonth !== null) && ($birthDay !== $member->birth_day || $birthMonth !== $member->birth_month)) {
                $message = 'Tanggal lahir sudah terisi. Minta kasir untuk mengubahnya';

                throw BusinessException::of(ErrorCode::ValidationError, $message, ['birth_day' => [$message]]);
            }

            [$birthDay, $birthMonth] = [$member->birth_day, $member->birth_month];
        }

        return $this->save->handle($member, MemberData::fromArray([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'birth_day' => $birthDay,
            'birth_month' => $birthMonth,
            'phone' => (string) $member->phone,
            'notes' => $member->notes,
        ]))['member'];
    }

    /** Bulan lahir (dasar voucher ulang tahun) sudah terisi = tanggal lahir tidak bisa diubah sendiri. */
    public static function birthDateLocked(Member $member): bool
    {
        return $member->birth_month !== null;
    }
}
