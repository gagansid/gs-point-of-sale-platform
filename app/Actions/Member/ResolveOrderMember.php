<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberSetting;

/**
 * Member yang dipasang ke order (checkout & open bill, ADR 0013). Program harus aktif di bisnis; outlet yang
 * dikecualikan tetap boleh memasang member (tercatat kunjungan, tanpa benefit). Member tenant lain, nonaktif, atau
 * dihapus → MEMBER_NOT_FOUND (tenant scope: tidak ditemukan).
 */
final class ResolveOrderMember
{
    /** @throws BusinessException MEMBER_PROGRAM_DISABLED, MEMBER_NOT_FOUND */
    public function handle(?string $memberId): ?Member
    {
        if ($memberId === null) {
            return null;
        }

        if (! MemberSetting::current()->enabled) {
            throw BusinessException::of(ErrorCode::MemberProgramDisabled, 'Program member belum diaktifkan');
        }

        $member = Member::query()->find($memberId);

        return $member !== null && $member->isUsable() ? $member : throw BusinessException::of(ErrorCode::MemberNotFound);
    }
}
