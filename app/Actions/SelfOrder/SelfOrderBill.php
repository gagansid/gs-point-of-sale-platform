<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Actions\Order\Data\ResolvedLine;
use App\Actions\Order\OrderWriter;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SelfOrderStatus;
use App\Models\Member;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Models\User;
use App\Services\Order\CalculationResult;
use App\Services\Order\MemberPricing;
use App\Services\Order\OrderNumberGenerator;
use App\Support\Money;

/**
 * Membuat tagihan (order `awaiting_payment`, kanal self_order) dari pesanan meja — dipakai Terima kasir (ADR 0014) dan
 * checkout bayar-dulu (ADR 0027). Harga & snapshot dari baris yang sudah dihitung server; dipanggil di dalam transaksi
 * dengan baris self_orders terkunci. Tagihan 0 (voucher menutup semua) langsung selesai.
 */
final class SelfOrderBill
{
    public function __construct(
        private readonly OrderWriter $writer,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /**
     * @param  list<ResolvedLine>  $lines  hasil ResolveOrderLines
     */
    public function create(SelfOrder $selfOrder, Outlet $outlet, Shift $shift, User $actor, ?Member $member, array $lines,
        MemberPricing $pricing, CalculationResult $totals, ?User $handledBy): Order
    {
        $order = new Order([
            'outlet_id' => $outlet->id,
            'shift_id' => $shift->id,
            'device_id' => $shift->device_id,
            'user_id' => $actor->id,
            'order_type' => OrderType::DineIn,
            'table_label' => mb_substr($selfOrder->diningTable->name ?? '', 0, 50),
            'notes' => mb_substr('Atas nama '.$selfOrder->customer_name.($selfOrder->notes !== null ? ' · '.$selfOrder->notes : ''), 0, 255),
        ]);
        $order->forceFill([
            'order_number' => $this->numbers->next($outlet),
            'status' => OrderStatus::AwaitingPayment,
            'channel' => OrderChannel::SelfOrder,
            'dining_table_id' => $selfOrder->dining_table_id,
            'accepted_at' => now(),
            'member_id' => $member?->id,
            'member_name' => $member?->name,
            'member_tier' => $pricing->tierName,
            'voucher_id' => $pricing->voucherId,
            'voucher_code' => $pricing->voucherLabel,
        ]);
        $this->writer->applyTotals($order, $totals, $outlet);
        $order->save();
        $this->writer->replaceItems($order, $lines, $totals);

        $selfOrder->forceFill([
            'status' => SelfOrderStatus::Accepted,
            'order_id' => $order->id,
            'handled_by' => $handledBy?->id,
            'handled_at' => $handledBy !== null ? now() : null,
        ])->save();

        // Tagihan 0 (voucher menutup semua): langsung selesai, tanpa pembayaran
        if (! Money::isPositive($order->grand_total)) {
            $this->writer->complete($order, $shift, $actor);
        }

        return $order;
    }
}
