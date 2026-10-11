<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\PointEntryType;
use App\Models\MemberPointEntry;
use App\Models\Order;
use App\Services\Member\PointLedger;
use App\Support\Money;

/**
 * Void order selesai (ADR 0013): poin yang didapat ditarik (saldo boleh minus bila sudah terpakai) dan
 * statistik kunjungan/belanja dikembalikan. Dipanggil VoidOrder di dalam transaksi; idempotent per order.
 */
final class ReverseOrderBenefits
{
    public function __construct(private readonly PointLedger $ledger) {}

    public function handle(Order $order): void
    {
        if ($order->member_id === null || $order->member === null) {
            return;
        }

        $member = $this->ledger->lock($order->member);
        $member->forceFill([
            'visit_count' => max(0, $member->visit_count - 1),
            'total_spent' => Money::max(Money::sub($member->total_spent, $order->grand_total), '0.00'),
            'spend_12m' => Money::max(Money::sub((string) $member->getAttribute('spend_12m'), $order->grand_total), '0.00'),
        ])->save();

        $earned = MemberPointEntry::query()->where('order_id', $order->id)->where('type', PointEntryType::Earn)->first();
        $reversed = MemberPointEntry::query()->where('order_id', $order->id)->where('type', PointEntryType::VoidReversal)->exists();

        if ($earned !== null && ! $reversed) {
            $this->ledger->debit($member, $earned->points, PointEntryType::VoidReversal, [
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'reason' => 'Order di-void',
            ], $earned->id);
        }
    }
}
