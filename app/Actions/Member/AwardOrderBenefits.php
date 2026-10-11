<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\PointEntryType;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\OutletMemberProgram;
use App\Services\Member\PointLedger;
use App\Services\Member\TierEvaluator;
use App\Support\Money;

/**
 * Benefit member saat order SELESAI (ADR 0013, Q62): statistik kunjungan & belanja, poin didapat.
 * Dipanggil OrderWriter::complete di dalam transaksi order; idempotent per order (poin hanya sekali).
 *
 * Poin = floor(dasar / earn_amount) × earn_points × pengali tier (M5); dasar = subtotal setelah diskon item & order, sebelum
 * service, pajak, dan pembulatan. Outlet dikecualikan / program nonaktif: hanya statistik kunjungan.
 */
final class AwardOrderBenefits
{
    public function __construct(
        private readonly PointLedger $ledger,
        private readonly TierEvaluator $tiers,
    ) {}

    public function handle(Order $order): void
    {
        if ($order->member_id === null || $order->member === null) {
            return;
        }

        $member = $this->ledger->lock($order->member);
        // Pengali poin dari tier SEBELUM order ini dihitung (tier baru berlaku untuk transaksi berikutnya)
        $multiplier = $member->tier->point_multiplier ?? '1.00';
        $member->forceFill([
            'visit_count' => $member->visit_count + 1,
            'total_spent' => Money::add($member->total_spent, $order->grand_total),
            'last_visit_at' => $order->completed_at ?? now(),
        ])->save();
        // Belanja 12 bulan & tier (M5): order ini sudah berstatus selesai di transaksi yang sama
        $this->tiers->refresh($member);

        $settings = MemberSetting::current();

        if (! $member->isUsable() || ! OutletMemberProgram::activeAt($order->outlet_id) || $order->points_earned > 0) {
            return;
        }

        $points = self::pointsFor(self::earnBase($order), $settings, $multiplier);

        if ($points <= 0) {
            return;
        }

        $this->ledger->credit($member, $points, PointEntryType::Earn, [
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->id,
        ], $settings->expiry_months > 0 ? now()->toImmutable()->addMonths($settings->expiry_months) : null);

        $order->forceFill(['points_earned' => $points])->save();
    }

    /**
     * Dasar poin: subtotal (setelah diskon item) − semua diskon order (manual, member, voucher, stempel), tanpa
     * service, pajak, pembulatan; bagian yang dibayar dengan poin tidak menghasilkan poin.
     */
    public static function earnBase(Order $order): string
    {
        $itemDiscounts = $order->items()->sum('discount');
        $orderLevel = Money::sub($order->discount_total, Money::fromDb($itemDiscounts));
        $paidWithPoints = Money::fromDb($order->payments()->where('category', PaymentCategory::Points)->where('status', PaymentStatus::Paid)->sum('amount'));

        return Money::max(Money::sub(Money::sub($order->subtotal, $orderLevel), $paidWithPoints), '0.00');
    }

    /** Poin = floor(floor(dasar / earn_amount) × earn_points × pengali tier). */
    public static function pointsFor(string $base, MemberSetting $settings, string $multiplier = '1.00'): int
    {
        if (! Money::isPositive($settings->earn_amount)) {
            return 0;
        }

        $points = (int) floor((float) bcdiv($base, $settings->earn_amount, 4)) * $settings->earn_points;

        return (int) floor((float) bcmul((string) $points, $multiplier, 4));
    }
}
