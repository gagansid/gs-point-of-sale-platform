<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Enums\PointEntryType;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\OutletMemberProgram;
use App\Services\Member\PointLedger;
use App\Support\Money;

/**
 * Penukaran poin sebagai pembayaran kategori `points` (ADR 0013, Q62). Dua tahap:
 * - check(): sebelum transaksi — member terpasang, program aktif di outlet, bukti (QR/PIN) diverifikasi;
 * - handle(): di dalam transaksi order, member terkunci — nominal kelipatan nilai poin, minimal & maksimal
 *   persen tagihan, saldo cukup; ledger `redeem` per pembayaran, orders.points_redeemed bertambah.
 */
final class RedeemPoints
{
    public function __construct(
        private readonly PointLedger $ledger,
        private readonly VerifyMemberProof $verifyProof,
    ) {}

    /**
     * @param  list<array{category: PaymentCategory, amount: string}>  $payments
     *
     * @throws BusinessException
     */
    public function check(array $payments, ?string $memberId, string $outletId, ?string $code, ?string $pin): void
    {
        if (! self::hasPoints($payments)) {
            return;
        }

        $member = $memberId !== null ? Member::query()->find($memberId) : null;

        if ($member === null || ! $member->isUsable()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Pasang member dulu untuk membayar dengan poin', ['member_id' => ['Pasang member dulu untuk membayar dengan poin']]);
        }

        if (! OutletMemberProgram::activeAt($outletId)) {
            throw BusinessException::of(ErrorCode::MemberProgramDisabled);
        }

        $this->verifyProof->handle($member, $code, $pin);
    }

    /**
     * @param  list<array{id: string, category: PaymentCategory, amount: string}>  $payments  pembayaran yang baru ditambahkan
     *
     * @throws BusinessException INSUFFICIENT_POINTS
     */
    public function handle(Order $order, array $payments): void
    {
        $pointsPayments = array_values(array_filter($payments, fn (array $p): bool => $p['category'] === PaymentCategory::Points));

        if ($pointsPayments === [] || $order->member_id === null) {
            return;
        }

        $settings = MemberSetting::current();
        $member = $this->ledger->lock(Member::query()->findOrFail($order->member_id));
        $value = $settings->redeem_value;

        if (! Money::isPositive($value)) {
            throw BusinessException::of(ErrorCode::InsufficientPoints, 'Penukaran poin belum diatur');
        }

        $points = [];
        foreach ($pointsPayments as $payment) {
            $p = bcdiv($payment['amount'], $value, 2);

            if (bccomp($p, (string) (int) $p, 2) !== 0) {
                throw BusinessException::of(ErrorCode::InsufficientPoints, 'Nominal poin harus kelipatan '.Money::format($value));
            }

            $points[$payment['id']] = (int) $p;
        }

        $total = array_sum($points) + $order->points_redeemed;
        $maxAmount = Money::percentOf($order->grand_total, (string) $settings->max_redeem_percent);

        $reason = match (true) {
            array_sum($points) < $settings->min_redeem_points => 'Minimal tukar '.$settings->min_redeem_points.' poin',
            Money::compare(Money::mul($value, $total), $maxAmount) > 0 => 'Maksimal '.$settings->max_redeem_percent.'% tagihan ('.Money::format($maxAmount).')',
            array_sum($points) > $member->points_balance => 'Saldo poin '.$member->name.' hanya '.$member->points_balance,
            default => null,
        };

        if ($reason !== null) {
            throw BusinessException::of(ErrorCode::InsufficientPoints, $reason, ['balance' => $member->points_balance]);
        }

        foreach ($points as $paymentId => $count) {
            $this->ledger->debit($member, $count, PointEntryType::Redeem, [
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);
        }

        $order->forceFill(['points_redeemed' => $total])->save();
    }

    /** @param list<array{category: PaymentCategory, amount: string}> $payments */
    private static function hasPoints(array $payments): bool
    {
        foreach ($payments as $payment) {
            if ($payment['category'] === PaymentCategory::Points) {
                return true;
            }
        }

        return false;
    }
}
