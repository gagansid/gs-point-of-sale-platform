<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Enums\PointEntryType;
use App\Models\Member;
use App\Models\MemberPointEntry;
use Carbon\CarbonImmutable;

/**
 * Penulisan ledger poin (ADR 0013, Q62). Dipanggil DI DALAM transaksi Action pemanggil, dengan baris member
 * terkunci (lock()). Kredit menyimpan `remaining` untuk FIFO; debit memakai kredit yang paling dulu kedaluwarsa.
 * `members.points_balance` = cache saldo, selalu diubah bersama baris ledger.
 */
final class PointLedger
{
    /** Kunci baris member (pola stok): transaksi bersamaan tidak saling menimpa saldo. */
    public function lock(Member $member): Member
    {
        return Member::query()->lockForUpdate()->findOrFail($member->id);
    }

    /**
     * @param  array{outlet_id?: string|null, order_id?: string|null, payment_id?: string|null, reason?: string|null, user_id?: string|null}  $attributes
     */
    public function credit(Member $member, int $points, PointEntryType $type, array $attributes = [], ?CarbonImmutable $expiresAt = null): MemberPointEntry
    {
        $member->points_balance += $points;
        $member->save();

        return MemberPointEntry::query()->create([
            ...$attributes,
            'member_id' => $member->id,
            'type' => $type,
            'points' => $points,
            'remaining' => $points,
            'balance_after' => $member->points_balance,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Kurangi poin: sisa kredit dipakai FIFO (paling dulu kedaluwarsa), $preferredCreditId lebih dulu (mis. poin dari
     * order yang di-void). Saldo boleh minus bila poin sudah terpakai (ADR 0013: jujur, terlihat di laporan).
     *
     * @param  array{outlet_id?: string|null, order_id?: string|null, payment_id?: string|null, reason?: string|null, user_id?: string|null}  $attributes
     */
    public function debit(Member $member, int $points, PointEntryType $type, array $attributes = [], ?string $preferredCreditId = null): MemberPointEntry
    {
        $this->consume($member, $points, $preferredCreditId);

        $member->points_balance -= $points;
        $member->save();

        return MemberPointEntry::query()->create([
            ...$attributes,
            'member_id' => $member->id,
            'type' => $type,
            'points' => -$points,
            'remaining' => 0,
            'balance_after' => $member->points_balance,
        ]);
    }

    private function consume(Member $member, int $points, ?string $preferredCreditId): void
    {
        $credits = MemberPointEntry::query()
            ->where('member_id', $member->id)->where('remaining', '>', 0)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredCreditId ?? ''])
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('created_at')
            ->lockForUpdate()->get();

        foreach ($credits as $credit) {
            if ($points <= 0) {
                break;
            }

            $used = min($points, $credit->remaining);
            $credit->forceFill(['remaining' => $credit->remaining - $used])->save();
            $points -= $used;
        }
    }
}
