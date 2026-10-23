<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Member\ResolveMemberPricing;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\PriceOrder;
use App\Actions\Order\ResolveOrderLines;
use App\Enums\ErrorCode;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\SelfOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kasir menerima pesanan meja lama yang masih `pending` (ADR 0014, Q68; alur baru ADR 0027 tanpa terima kasir): order
 * dibuat di shift perangkat kasir dengan status
 * `awaiting_payment` (nomor order, harga & snapshot saat ini, member & voucher dicek ulang). Stok dipotong saat lunas.
 * Total 0 (mis. voucher penuh) langsung selesai. Pesanan yang sudah ditangani → ORDER_NOT_PENDING; lewat batas waktu →
 * ORDER_EXPIRED.
 */
final class AcceptSelfOrder
{
    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly PriceOrder $priceOrder,
        private readonly SelfOrderBill $bill,
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

        return DB::transaction(function () use ($actor, $shift, $outlet, $selfOrder, $member, $lines, $pricing, $totals): Order {
            $locked = SelfOrder::query()->lockForUpdate()->findOrFail($selfOrder->id);
            self::ensurePending($locked);

            return $this->bill->create($locked, $outlet, $shift, $actor, $member, $lines, $pricing, $totals, handledBy: $actor);
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
