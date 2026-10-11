<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Enums\PointEntryType;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Models\User;
use App\Services\Member\PointLedger;
use Illuminate\Support\Facades\DB;

/**
 * Penyesuaian poin manual (ADR 0013, member.points_adjust): tambah (kredit, ikut kedaluwarsa) atau kurangi
 * (tidak boleh melebihi saldo). Alasan wajib; tercatat di ledger beserta pelakunya.
 */
final class AdjustMemberPoints
{
    public function __construct(private readonly PointLedger $ledger) {}

    /** @throws BusinessException */
    public function handle(Member $member, int $points, string $reason, User $by): MemberPointEntry
    {
        if ($points === 0 || abs($points) > 1_000_000) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Jumlah poin tidak valid', ['points' => ['Isi jumlah poin selain 0 (maks. 1.000.000)']]);
        }

        if (trim($reason) === '') {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan wajib diisi', ['reason' => ['Alasan wajib diisi']]);
        }

        return DB::transaction(function () use ($member, $points, $reason, $by): MemberPointEntry {
            $locked = $this->ledger->lock($member);

            if ($locked->isAnonymized()) {
                throw BusinessException::of(ErrorCode::MemberNotFound);
            }

            $attributes = ['reason' => mb_substr(trim($reason), 0, 255), 'user_id' => $by->id];

            if ($points > 0) {
                $months = MemberSetting::current()->expiry_months;

                return $this->ledger->credit($locked, $points, PointEntryType::Adjust, $attributes, $months > 0 ? now()->toImmutable()->addMonths($months) : null);
            }

            if (-$points > $locked->points_balance) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Poin yang dikurangi melebihi saldo', ['points' => ['Saldo poin hanya '.$locked->points_balance]]);
            }

            return $this->ledger->debit($locked, -$points, PointEntryType::Adjust, $attributes);
        });
    }
}
