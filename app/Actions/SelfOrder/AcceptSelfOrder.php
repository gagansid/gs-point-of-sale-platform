<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Member\ResolveMemberPricing;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\OrderWriter;
use App\Actions\Order\PriceOrder;
use App\Actions\Order\ResolveOrderLines;
use App\Enums\ErrorCode;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\SelfOrder;
use App\Models\User;
use App\Services\Order\OrderNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Kasir menerima pesanan meja (ADR 0014, Q68): order dibuat di shift perangkat kasir dengan status
 * `awaiting_payment` (nomor order, harga & snapshot saat ini, member & voucher dicek ulang). Stok dipotong saat lunas.
 * Total 0 (mis. voucher penuh) langsung selesai. Pesanan yang sudah ditangani → ORDER_NOT_PENDING; lewat batas waktu →
 * ORDER_EXPIRED.
 */
final class AcceptSelfOrder
{
    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly PriceOrder $priceOrder,
        private readonly OrderWriter $writer,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /** @throws BusinessException */
    public function handle(User $actor, Device $device, SelfOrder $selfOrder): Order
    {
        if (! $actor->can('self_order.handle')) {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        // Pesanan outlet lain dari perangkat ini → tidak ditemukan
        if ($selfOrder->outlet_id !== $device->outlet_id) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        self::ensurePending($selfOrder);
        $shift = CheckoutOrder::openShiftOf($device);
        $outlet = Outlet::query()->findOrFail($selfOrder->outlet_id);

        if (ExpireSelfOrders::isPendingExpired($selfOrder)) {
            app(ExpireSelfOrders::class)->expirePending($selfOrder);
            throw BusinessException::of(ErrorCode::OrderExpired, 'Pesanan sudah terlalu lama menunggu dan kedaluwarsa');
        }

        $member = $selfOrder->member_id !== null && MemberSetting::current()->enabled ? Member::query()->find($selfOrder->member_id) : null;
        $member = $member?->isUsable() ? $member : null;
        $lines = $this->resolveLines->handle(array_map(fn (array $i): array => [...$i, 'discount' => '0.00'], $selfOrder->items), $outlet);
        $pricing = app(ResolveMemberPricing::class)->handle($member, $outlet, $lines, new BenefitSelection(voucherCode: $selfOrder->voucher_code));
        $totals = $this->priceOrder->totals($outlet, $lines, null, '0.00', $pricing);

        return DB::transaction(function () use ($actor, $device, $shift, $outlet, $selfOrder, $member, $lines, $pricing, $totals): Order {
            $locked = SelfOrder::query()->lockForUpdate()->findOrFail($selfOrder->id);
            self::ensurePending($locked);

            $order = new Order([
                'outlet_id' => $outlet->id,
                'shift_id' => $shift->id,
                'device_id' => $device->id,
                'user_id' => $actor->id,
                'order_type' => OrderType::DineIn,
                'table_label' => mb_substr($locked->diningTable->name ?? '', 0, 50),
                'notes' => mb_substr('Atas nama '.$locked->customer_name.($locked->notes !== null ? ' · '.$locked->notes : ''), 0, 255),
            ]);
            $order->forceFill([
                'order_number' => $this->numbers->next($outlet),
                'status' => OrderStatus::AwaitingPayment,
                'channel' => OrderChannel::SelfOrder,
                'dining_table_id' => $locked->dining_table_id,
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

            $locked->forceFill([
                'status' => SelfOrderStatus::Accepted,
                'order_id' => $order->id,
                'handled_by' => $actor->id,
                'handled_at' => now(),
            ])->save();

            // Tagihan 0 (voucher menutup semua): langsung selesai, tanpa pembayaran
            if (! Money::isPositive($order->grand_total)) {
                $this->writer->complete($order, $shift, $actor);
            }

            return $order;
        });
    }

    /** @throws BusinessException */
    public static function ensurePending(SelfOrder $selfOrder): void
    {
        if ($selfOrder->status === SelfOrderStatus::Expired) {
            throw BusinessException::of(ErrorCode::OrderExpired);
        }

        if ($selfOrder->status !== SelfOrderStatus::Pending) {
            throw BusinessException::of(ErrorCode::OrderNotPending);
        }
    }
}
