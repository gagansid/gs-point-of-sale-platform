<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\ReceivableEntryType;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\ReceivableEntry;
use App\Services\Member\ReceivableLedger;
use App\Support\Money;

/**
 * Pembayaran kategori `receivable` = kasbon member (ADR 0023, Q105). Tiga tahap:
 * - check(): sebelum transaksi — kasbon aktif, member terpasang & batas diatur (bukti QR/PIN diverifikasi RedeemPoints::check);
 * - handle(): di dalam transaksi order, member terkunci — saldo + kasbon ≤ batas, ledger `charge` per pembayaran;
 * - reverse(): void — semua kasbon order yang belum dibalik dikembalikan (ledger `void`).
 */
final class ChargeReceivable
{
    public function __construct(private readonly ReceivableLedger $ledger) {}

    /**
     * @param  list<array{category: PaymentCategory, amount: string}>  $payments
     *
     * @throws BusinessException
     */
    public function check(array $payments, ?string $memberId): void
    {
        if (! self::hasReceivable($payments)) {
            return;
        }

        if (! MemberSetting::current()->credit_enabled) {
            throw self::invalid('Kasbon tidak aktif');
        }

        $member = $memberId !== null ? Member::query()->find($memberId) : null;
        if ($member === null || ! $member->isUsable()) {
            throw self::invalid('Pasang member dulu untuk kasbon', 'member_id');
        }

        if (! Money::isPositive((string) $member->effectiveCreditLimit())) {
            throw self::invalid("{$member->name} belum punya batas kasbon");
        }
    }

    /**
     * @param  list<array{id: string, category: PaymentCategory, amount: string}>  $payments  pembayaran yang baru ditambahkan
     *
     * @throws BusinessException
     */
    public function handle(Order $order, array $payments, ?string $userId = null): void
    {
        $charges = array_values(array_filter($payments, fn (array $p): bool => $p['category'] === PaymentCategory::Receivable));

        if ($charges === [] || $order->member_id === null) {
            return;
        }

        $member = $this->ledger->lock(Member::query()->findOrFail($order->member_id));
        $limit = Money::fromDb($member->effectiveCreditLimit());
        $after = Money::add($member->receivable_balance, ...array_column($charges, 'amount'));

        if (Money::compare($after, $limit) > 0) {
            $left = Money::max(Money::sub($limit, $member->receivable_balance), '0.00');
            throw self::invalid("Sisa batas kasbon {$member->name} ".Money::format($left).' (batas '.Money::format($limit).')');
        }

        foreach ($charges as $payment) {
            $this->ledger->record($member, ReceivableEntryType::Charge, $payment['amount'], [
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'payment_id' => $payment['id'],
                'user_id' => $userId,
                'note' => $order->order_number,
            ]);
        }
    }

    /** Void (ADR 0023): kasbon order dibalik; dipanggil di dalam transaksi void. Idempotent. */
    public function reverse(Order $order, ?string $userId = null): void
    {
        if ($order->member_id === null || ReceivableEntry::query()->where('order_id', $order->id)->where('type', ReceivableEntryType::Void)->exists()) {
            return;
        }

        $charged = Money::fromDb(ReceivableEntry::query()->where('order_id', $order->id)
            ->whereIn('type', [ReceivableEntryType::Charge, ReceivableEntryType::Refund])->sum('amount'));

        if (! Money::isPositive($charged)) {
            return;
        }

        $member = $this->ledger->lock(Member::query()->findOrFail($order->member_id));
        $this->ledger->record($member, ReceivableEntryType::Void, Money::sub('0.00', $charged), [
            'outlet_id' => $order->outlet_id, 'order_id' => $order->id, 'user_id' => $userId, 'note' => 'Void '.$order->order_number,
        ]);
    }

    /** Nilai order yang dibayar kasbon (pembayaran lunas). */
    public static function paidWithCredit(Order $order): string
    {
        return Money::fromDb($order->payments()->where('category', PaymentCategory::Receivable)->where('status', PaymentStatus::Paid)->sum('amount'));
    }

    /** @param list<array{category: PaymentCategory, amount: string}> $payments */
    private static function hasReceivable(array $payments): bool
    {
        foreach ($payments as $payment) {
            if ($payment['category'] === PaymentCategory::Receivable) {
                return true;
            }
        }

        return false;
    }

    private static function invalid(string $message, string $field = 'payments'): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);
    }
}
