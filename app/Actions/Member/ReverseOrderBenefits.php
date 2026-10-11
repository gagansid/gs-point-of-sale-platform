<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\PointEntryType;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Services\Member\PointLedger;
use App\Services\Member\StampCards;
use App\Services\Member\TierEvaluator;
use App\Services\Member\Vouchers;
use App\Support\Money;

/**
 * Void order selesai (ADR 0013): poin yang didapat ditarik (saldo boleh minus bila sudah terpakai) dan
 * statistik kunjungan/belanja & tier dikembalikan. Dipanggil VoidOrder di dalam transaksi; idempotent per order.
 */
final class ReverseOrderBenefits
{
    public function __construct(
        private readonly PointLedger $ledger,
        private readonly TierEvaluator $tiers,
        private readonly StampCards $stamps,
        private readonly Vouchers $vouchers,
    ) {}

    public function handle(Order $order): void
    {
        // Kuota voucher kembali (M7), juga untuk order tanpa member
        $this->vouchers->reverse($order);

        if ($order->member_id === null || $order->member === null) {
            return;
        }

        $member = $this->ledger->lock($order->member);
        $member->forceFill([
            'visit_count' => max(0, $member->visit_count - 1),
            'total_spent' => Money::max(Money::sub($member->total_spent, $order->grand_total), '0.00'),
        ])->save();
        // Status void ditulis setelah ini: order dikecualikan dari belanja 12 bulan (tier bisa turun, M5)
        $this->tiers->refresh($member, excludeOrderId: $order->id);
        // Stempel didapat ditarik, stempel yang ditukar dikembalikan (M6)
        $this->stamps->reverse($member, $order);

        $earned = MemberPointEntry::query()->where('order_id', $order->id)->where('type', PointEntryType::Earn)->first();
        $reversed = MemberPointEntry::query()->where('order_id', $order->id)->where('type', PointEntryType::VoidReversal)->where('points', '<', 0)->exists();

        if ($earned !== null && ! $reversed) {
            $this->ledger->debit($member, $earned->points, PointEntryType::VoidReversal, [
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'reason' => 'Order di-void',
            ], $earned->id);
        }

        // Poin yang ditukar di order ini dikembalikan (kredit baru, ikut masa berlaku)
        $redeemed = (int) -MemberPointEntry::query()->where('order_id', $order->id)->where('type', PointEntryType::Redeem)->sum('points');
        $refunded = MemberPointEntry::query()->where('order_id', $order->id)->where('type', PointEntryType::VoidReversal)->where('points', '>', 0)->exists();

        if ($redeemed > 0 && ! $refunded) {
            $months = MemberSetting::current()->expiry_months;
            $this->ledger->credit($member, $redeemed, PointEntryType::VoidReversal, [
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'reason' => 'Poin dikembalikan (void)',
            ], $months > 0 ? now()->toImmutable()->addMonths($months) : null);
        }
    }
}
