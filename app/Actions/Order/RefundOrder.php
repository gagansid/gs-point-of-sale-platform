<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Actions\Member\ChargeReceivable;
use App\Actions\Order\Data\RefundData;
use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\PointEntryType;
use App\Enums\ReceivableEntryType;
use App\Enums\RefundMethod;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\Member\PointLedger;
use App\Services\Member\ReceivableLedger;
use App\Services\Member\TierEvaluator;
use App\Services\Order\OrderCalculator;
use App\Services\Shift\ShiftSummary;
use App\Services\Stock\StockConsumption;
use App\Support\Idempotency;
use App\Support\Money;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Retur sebagian per item (ADR 0018, POST /orders/{id}/refunds):
 * - order selesai di outlet perangkat, maks. pos.refund.max_days hari; alasan wajib;
 * - izin order.refund atau PIN approver (≠ pelaku); shift wajib terbuka di perangkat (uang keluar dari laci itu);
 * - nilai = bagian proporsional grand total (OrderCalculator::refundShare), retur terakhir menghabiskan sisa;
 * - bagian yang dibayar poin kembali sebagai poin, poin hasil belanja ditarik proporsional, belanja member dikurangi;
 * - tunai = kas keluar alasan Retur (≤ isi laci), manual = hanya dicatat; stok kembali bila restock.
 * ID dari klien = idempotency key.
 */
final class RefundOrder
{
    public function __construct(
        private readonly VerifyApproval $verifyApproval,
        private readonly ShiftSummary $summary,
        private readonly PointLedger $ledger,
        private readonly TierEvaluator $tiers,
    ) {}

    /**
     * @return array{refund: OrderRefund, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, Order $order, RefundData $data, ?ApprovalData $approval = null): array
    {
        if (($existing = Idempotency::existing(OrderRefund::class, $data->id, self::replayableAt($device))) !== null) {
            return ['refund' => self::load($existing), 'replayed' => true];
        }

        CheckoutOrder::ensureAtOutlet($order, $device->outlet_id);
        self::ensureRefundable($order);

        if ($data->items === []) {
            throw self::invalid('items', 'Pilih minimal satu item yang diretur.');
        }

        // Di luar transaksi: percobaan PIN salah tetap tercatat
        $approverId = $this->verifyApproval->handle($actor, $order->outlet_id, 'order.refund', $approval);
        $shift = CheckoutOrder::openShiftOf($device);

        try {
            $refund = DB::transaction(fn (): OrderRefund => $this->write($actor, $device, $order, $shift, $data, $approverId));
        } catch (UniqueConstraintViolationException) {
            // Request kembar lolos pengecekan awal bersamaan; primary key menjadi penjaga terakhir
            $existing = Idempotency::existing(OrderRefund::class, $data->id, self::replayableAt($device))
                ?? throw BusinessException::of(ErrorCode::NotFound);

            return ['refund' => self::load($existing), 'replayed' => true];
        }

        Log::warning('Order diretur', [
            'order_id' => $order->id,
            'refund_id' => $refund->id,
            'user_id' => $actor->id,
            'approved_by' => $approverId,
            'amount' => $refund->amount,
            'method' => $refund->method->value,
        ]);

        return ['refund' => self::load($refund), 'replayed' => false];
    }

    /** @throws BusinessException */
    private function write(User $actor, Device $device, Order $order, Shift $shift, RefundData $data, ?string $approverId): OrderRefund
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        self::ensureRefundable($locked);
        $lockedShift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

        if (! $lockedShift->isOpen()) {
            throw BusinessException::of(ErrorCode::ShiftNotOpen);
        }

        $items = OrderItem::query()->where('order_id', $locked->id)->lockForUpdate()->get();
        $lines = [];
        $total = '0.00';

        foreach ($data->items as $itemId => $qty) {
            $item = $items->firstWhere('id', (string) $itemId) ?? throw self::invalid('items', 'Item tidak ada di order ini.');
            $left = $item->qty - $item->refunded_qty;

            if ($qty > $left) {
                throw self::invalid('items', "{$item->product_name}: maksimal {$left} yang bisa diretur.");
            }

            $amount = OrderCalculator::refundShare($locked->grand_total, $locked->subtotal, $item->line_total, $item->qty, $qty);
            $lines[] = ['item' => $item, 'qty' => $qty, 'amount' => $amount];
            $total = Money::add($total, $amount);
        }

        // Retur yang menghabiskan seluruh item memakai sisa nilai order (tanpa selisih pembulatan)
        $remaining = Money::sub($locked->grand_total, $locked->refunded_total);
        $allReturned = $items->every(fn (OrderItem $item): bool => $item->qty - $item->refunded_qty === ($data->items[$item->id] ?? 0));
        if ($allReturned || Money::compare($total, $remaining) > 0) {
            $last = array_key_last($lines);
            $lines[$last]['amount'] = Money::add($lines[$last]['amount'], Money::sub($remaining, $total));
            $total = $remaining;
        }

        $refundedAfter = Money::add($locked->refunded_total, $total);
        [$pointsMoney, $pointsReturned, $pointsReversed] = $this->pointShares($locked, $total, $refundedAfter);
        // Bagian yang dibayar kasbon mengurangi kasbon member, bukan dibayar uang (ADR 0023)
        $creditShare = Money::isPositive($locked->grand_total)
            ? Money::round(bcdiv(bcmul($total, ChargeReceivable::paidWithCredit($locked), 8), $locked->grand_total, 8))
            : '0.00';
        $payout = Money::max(Money::sub(Money::sub($total, $pointsMoney), $creditShare), '0.00');

        $movementId = null;
        if ($data->method === RefundMethod::Cash && Money::isPositive($payout)) {
            $inDrawer = $this->summary->for($lockedShift)['expected_cash'];
            if (Money::compare($payout, $inDrawer) > 0) {
                throw self::invalid('method', 'Uang di laci tidak cukup ('.Money::format($inDrawer).'). Pilih pengembalian manual.');
            }

            $movement = new CashMovement([
                'outlet_id' => $lockedShift->outlet_id,
                'shift_id' => $lockedShift->id,
                'user_id' => $actor->id,
                'type' => CashMovementType::Out,
                'reason' => CashMovementReason::Refund,
                'amount' => $payout,
                'note' => 'Retur '.$locked->order_number,
                'approved_by' => $approverId,
            ]);
            $movement->id = (string) Str::uuid7();
            $movement->save();
            $movementId = $movement->id;
        }

        $refund = new OrderRefund([
            'outlet_id' => $locked->outlet_id,
            'order_id' => $locked->id,
            'shift_id' => $lockedShift->id,
            'device_id' => $device->id,
            'user_id' => $actor->id,
            'approved_by' => $approverId,
            'reason' => $data->reason,
            'method' => $data->method,
            'restock' => $data->restock,
            'amount' => $total,
            'payout_amount' => $payout,
            'points_returned' => $pointsReturned,
            'points_reversed' => $pointsReversed,
            'cash_movement_id' => $movementId,
        ]);
        $refund->id = $data->id;
        $refund->save();

        foreach ($lines as $line) {
            /** @var OrderItem $item */
            $item = $line['item'];
            OrderRefundItem::query()->create([
                'order_refund_id' => $refund->id,
                'order_item_id' => $item->id,
                'product_name' => $item->product_name,
                'qty' => $line['qty'],
                'amount' => $line['amount'],
            ]);
            $item->forceFill(['refunded_qty' => $item->refunded_qty + $line['qty']])->save();

            if ($data->restock) {
                self::restock($locked, $item, $line['qty'], $actor, $refund);
            }
        }

        $locked->forceFill(['refunded_total' => $refundedAfter])->save();
        $this->adjustMember($locked, $refund, $total);

        if (Money::isPositive($creditShare) && $locked->member_id !== null) {
            $member = app(ReceivableLedger::class)->lock(Member::query()->findOrFail($locked->member_id));
            app(ReceivableLedger::class)->record($member, ReceivableEntryType::Refund, Money::sub('0.00', $creditShare), [
                'outlet_id' => $locked->outlet_id, 'order_id' => $locked->id, 'user_id' => $actor->id, 'note' => 'Retur '.$locked->order_number,
            ]);
        }

        return $refund;
    }

    /**
     * Bagian poin: [nilai uang yang dibayar poin, poin pembayaran dikembalikan, poin hasil belanja ditarik].
     * Dihitung kumulatif terhadap total retur order agar pembulatan tidak menumpuk antar retur.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function pointShares(Order $order, string $amount, string $refundedAfter): array
    {
        if ($order->member_id === null || ! Money::isPositive($order->grand_total)) {
            return ['0.00', 0, 0];
        }

        $pointsPaid = Money::fromDb($order->payments()->where('category', PaymentCategory::Points)->where('status', PaymentStatus::Paid)->sum('amount'));
        $entries = MemberPointEntry::query()->where('order_id', $order->id);
        $redeemed = (int) -(clone $entries)->where('type', PointEntryType::Redeem)->sum('points');
        $earned = (int) (clone $entries)->where('type', PointEntryType::Earn)->sum('points');
        $returnedBefore = (int) (clone $entries)->where('type', PointEntryType::Refund)->where('points', '>', 0)->sum('points');
        $reversedBefore = (int) -(clone $entries)->where('type', PointEntryType::Refund)->where('points', '<', 0)->sum('points');

        $ratio = fn (int $points): int => (int) floor((float) bcdiv(bcmul((string) $points, $refundedAfter, 8), $order->grand_total, 8));

        return [
            Money::round(bcdiv(bcmul($amount, $pointsPaid, 8), $order->grand_total, 8)),
            max(0, $ratio($redeemed) - $returnedBefore),
            max(0, $ratio($earned) - $reversedBefore),
        ];
    }

    private function adjustMember(Order $order, OrderRefund $refund, string $amount): void
    {
        if ($order->member_id === null || $order->member === null) {
            return;
        }

        $member = $this->ledger->lock($order->member);
        $member->forceFill(['total_spent' => Money::max(Money::sub($member->total_spent, $amount), '0.00')])->save();
        $attributes = ['outlet_id' => $order->outlet_id, 'order_id' => $order->id, 'reason' => 'Retur '.$order->order_number];

        if ($refund->points_reversed > 0) {
            $this->ledger->debit($member, $refund->points_reversed, PointEntryType::Refund, $attributes);
        }

        if ($refund->points_returned > 0) {
            $months = MemberSetting::current()->expiry_months;
            $this->ledger->credit($member, $refund->points_returned, PointEntryType::Refund, $attributes, $months > 0 ? now()->toImmutable()->addMonths($months) : null);
        }

        $this->tiers->refresh($member);
    }

    /** Stok kembali = konsumsi resep saat ini × qty retur (ADR 0020); hanya produk/bahan yang dilacak. */
    private static function restock(Order $order, OrderItem $item, int $qty, User $actor, OrderRefund $refund): void
    {
        $optionIds = $item->options()->pluck('option_id')->map(fn (mixed $id): string => (string) $id)->all();

        foreach (app(StockConsumption::class)->for($item->product_id, $optionIds, $qty) as $productId => $change) {
            OrderWriter::changeStock($order, $productId, $change, $actor, StockMovementType::Refund, $refund->id);
        }
    }

    /** @throws BusinessException */
    public static function ensureRefundable(Order $order): void
    {
        if ($order->status !== OrderStatus::Completed) {
            throw self::invalid('order', 'Hanya transaksi selesai yang bisa diretur.');
        }

        if ($order->isFullyRefunded()) {
            throw self::invalid('order', 'Semua item transaksi ini sudah diretur.');
        }

        $maxDays = (int) config('pos.refund.max_days');
        if ($order->completed_at !== null && $order->completed_at->lt(now()->subDays($maxDays))) {
            throw self::invalid('order', "Retur hanya bisa sampai {$maxDays} hari setelah transaksi.");
        }
    }

    public static function load(OrderRefund $refund): OrderRefund
    {
        return $refund->load(['items', 'user:id,name', 'approver:id,name']);
    }

    private static function invalid(string $field, string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);
    }

    /**
     * Kiriman ulang hanya mengembalikan retur di outlet perangkat ini; selain itu 404 (GSPOS-SEC-001).
     *
     * @return Closure(OrderRefund): bool
     */
    private static function replayableAt(Device $device): Closure
    {
        return fn (OrderRefund $refund): bool => $refund->outlet_id === $device->outlet_id;
    }
}
