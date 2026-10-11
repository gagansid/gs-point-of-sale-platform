<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\PointEntryType;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Services\Member\PointLedger;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hanguskan sisa poin yang lewat masa berlaku (ADR 0013, Q62). Command `pos:expire-member-points` harian;
 * semua tenant, satu transaksi per member. Aman diulang (kredit yang sudah habis dilewati).
 */
final class ExpireMemberPoints
{
    public function __construct(private readonly PointLedger $ledger) {}

    /** @return int jumlah poin yang dihanguskan */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $total = 0;

        $due = MemberPointEntry::allTenants()->where('remaining', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', $now)
            ->select('tenant_id', 'member_id')->distinct()->get();

        foreach ($due as $row) {
            $total += TenantContext::run((string) $row->tenant_id, fn (): int => DB::transaction(function () use ($row, $now): int {
                $member = $this->ledger->lock(Member::query()->findOrFail($row->member_id));
                $expired = 0;

                $credits = MemberPointEntry::query()->where('member_id', $member->id)->where('remaining', '>', 0)
                    ->whereNotNull('expires_at')->where('expires_at', '<=', $now)->lockForUpdate()->get();

                foreach ($credits as $credit) {
                    $points = $credit->remaining;
                    $this->ledger->debit($member, $points, PointEntryType::Expire, ['reason' => 'Kedaluwarsa'], $credit->id);
                    $expired += $points;
                }

                return $expired;
            }));
        }

        return $total;
    }
}
