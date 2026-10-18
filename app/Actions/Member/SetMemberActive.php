<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Support\Quotas;
use Illuminate\Support\Facades\DB;

/** Aktifkan/nonaktifkan member (ADR 0013). Member nonaktif tidak bisa dipasang ke transaksi baru. */
final class SetMemberActive
{
    /** @throws BusinessException */
    public function handle(Member $member, bool $active): Member
    {
        if ($member->isAnonymized()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Member sudah dihapus');
        }

        DB::transaction(function () use ($member, $active): void {
            // Aktif lagi = menambah pemakaian kuota member (ADR 0025)
            if ($active && ! $member->is_active) {
                Quotas::ensureAvailable($member->tenant_id, Quotas::MEMBERS);
            }

            $member->forceFill(['is_active' => $active])->save();
        });

        return $member;
    }
}
