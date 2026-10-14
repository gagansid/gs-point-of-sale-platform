<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Enums\OrderStatus;
use App\Models\Member;
use App\Models\MemberTier;
use App\Models\Order;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evaluasi tier member (ADR 0013, Q64): total belanja 12 bulan bergulir = Σ (grand_total − retur) order SELESAI (void tidak
 * dihitung) sejak 12 bulan lalu; tier = tier tertinggi yang batasnya terpenuhi (bisa naik atau turun).
 * `members.spend_12m` & `tier_id` adalah cache yang ditulis hanya lewat kelas ini. Dipanggil di dalam transaksi
 * Action pemanggil (order selesai/void) atau per tenant oleh jadwal harian.
 */
final class TierEvaluator
{
    /**
     * Hitung ulang satu member. $excludeOrderId: order yang sedang di-void (statusnya baru berubah setelah ini).
     *
     * @param  Collection<int, MemberTier>|null  $tiers  urut naik; null = dibaca dari DB
     */
    public function refresh(Member $member, ?string $excludeOrderId = null, ?Collection $tiers = null, ?CarbonImmutable $now = null): Member
    {
        $spend = Money::fromDb(Order::query()
            ->where('member_id', $member->id)
            ->where('status', OrderStatus::Completed)
            ->where('completed_at', '>=', self::windowStart($now))
            ->when($excludeOrderId !== null, fn ($q) => $q->whereKeyNot($excludeOrderId))
            // Dikurangi retur sebagian (ADR 0018)
            ->sum(DB::raw('grand_total - refunded_total')));

        return $this->apply($member, $spend, $tiers ?? MemberTier::ordered());
    }

    /**
     * Hitung ulang semua member tenant aktif (jadwal harian & setelah tier diubah). Satu query agregat; hanya
     * member yang nilainya berubah yang disimpan.
     *
     * @return int jumlah member yang tier-nya berubah
     */
    public function refreshAll(?CarbonImmutable $now = null): int
    {
        $tiers = MemberTier::ordered();
        $spends = Order::query()
            ->whereNotNull('member_id')
            ->where('status', OrderStatus::Completed)
            ->where('completed_at', '>=', self::windowStart($now))
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(grand_total - refunded_total) AS spend')
            ->pluck('spend', 'member_id');

        $changed = 0;

        // Member dengan belanja di jendela, atau yang masih menyimpan tier/belanja lama (bisa turun ke 0)
        Member::query()
            ->where(fn ($q) => $q->whereIn('id', $spends->keys())->orWhereNotNull('tier_id')->orWhere('spend_12m', '>', 0))
            ->chunkById(500, function (Collection $members) use ($spends, $tiers, &$changed): void {
                foreach ($members as $member) {
                    $before = $member->tier_id;
                    $this->apply($member, Money::fromDb($spends[$member->id] ?? 0), $tiers);
                    $changed += $member->tier_id !== $before ? 1 : 0;
                }
            });

        return $changed;
    }

    /** @param  Collection<int, MemberTier>  $tiers */
    private function apply(Member $member, string $spend, Collection $tiers): Member
    {
        // Member dihapus (anonim) tetap dihitung belanjanya, tetapi tanpa tier
        $tierId = $member->isAnonymized() ? null : MemberTier::forSpend($spend, $tiers)?->id;

        if (Money::compare(Money::fromDb($member->spend_12m), $spend) !== 0 || $member->tier_id !== $tierId) {
            // Tanpa audit log: perubahan otomatis dari transaksi, bukan suntingan pengguna
            activity()->withoutLogs(fn () => $member->forceFill(['spend_12m' => $spend, 'tier_id' => $tierId])->save());
        }

        return $member;
    }

    private static function windowStart(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subMonths(12);
    }
}
