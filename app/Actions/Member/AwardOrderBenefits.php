<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\PointEntryType;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\OutletMemberProgram;
use App\Services\Member\PointLedger;
use App\Support\Money;

/**
 * Benefit member saat order SELESAI (ADR 0013, Q62): statistik kunjungan & belanja, poin didapat.
 * Dipanggil OrderWriter::complete di dalam transaksi order; idempotent per order (poin hanya sekali).
 *
 * Poin = floor(dasar / earn_amount) × earn_points; dasar = subtotal setelah diskon item & order, sebelum
 * service, pajak, dan pembulatan. Outlet dikecualikan / program nonaktif: hanya statistik kunjungan.
 */
final class AwardOrderBenefits
{
    public function __construct(private readonly PointLedger $ledger) {}

    public function handle(Order $order): void
    {
        if ($order->member_id === null || $order->member === null) {
            return;
        }

        $member = $this->ledger->lock($order->member);
        $member->forceFill([
            'visit_count' => $member->visit_count + 1,
            'total_spent' => Money::add($member->total_spent, $order->grand_total),
            'spend_12m' => Money::add((string) $member->getAttribute('spend_12m'), $order->grand_total),
            'last_visit_at' => $order->completed_at ?? now(),
        ])->save();

        $settings = MemberSetting::current();

        if (! $member->isUsable() || ! OutletMemberProgram::activeAt($order->outlet_id) || $order->points_earned > 0) {
            return;
        }

        $points = self::pointsFor(self::earnBase($order), $settings);

        if ($points <= 0) {
            return;
        }

        $this->ledger->credit($member, $points, PointEntryType::Earn, [
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->id,
        ], $settings->expiry_months > 0 ? now()->toImmutable()->addMonths($settings->expiry_months) : null);

        $order->forceFill(['points_earned' => $points])->save();
    }

    /** Dasar poin: subtotal (setelah diskon item) − diskon order; tanpa service, pajak, pembulatan. */
    public static function earnBase(Order $order): string
    {
        return Money::max(Money::sub($order->subtotal, $order->order_discount), '0.00');
    }

    public static function pointsFor(string $base, MemberSetting $settings): int
    {
        if (! Money::isPositive($settings->earn_amount)) {
            return 0;
        }

        return (int) floor((float) bcdiv($base, $settings->earn_amount, 4)) * $settings->earn_points;
    }
}
