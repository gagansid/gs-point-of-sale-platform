<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Actions\Member\RedeemPoints;
use App\Actions\Order\OrderWriter;
use App\Enums\ErrorCode;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\OutletMemberProgram;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Member membayar pesanan meja dengan poin dari HP (ADR 0014, S4): bukti pemilik = sesi member yang masuk (bukan QR/PIN
 * kasir). Poin dipakai sebanyak mungkin sesuai aturan program (kelipatan nilai poin, minimal tukar, maks. % tagihan,
 * saldo); sisa tagihan dibayar di kasir/online. Lunas → order selesai di shift kasir penerima.
 */
final class PayWithPointsFromPhone
{
    public function __construct(
        private readonly OrderWriter $writer,
        private readonly RedeemPoints $redeem,
    ) {}

    /** @throws BusinessException */
    public function handle(Order $order, Member $member): Order
    {
        return DB::transaction(function () use ($order, $member): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->channel !== OrderChannel::SelfOrder || $locked->member_id !== $member->id) {
                throw BusinessException::of(ErrorCode::NotFound);
            }

            if ($locked->status !== OrderStatus::AwaitingPayment) {
                throw BusinessException::of($locked->status === OrderStatus::Expired ? ErrorCode::OrderExpired : ErrorCode::OrderAlreadyClosed);
            }

            $points = self::maxPoints($locked, $member->refresh());
            if ($points <= 0) {
                throw BusinessException::of(ErrorCode::InsufficientPoints, 'Poin belum cukup untuk dipakai di pesanan ini');
            }

            $method = PaymentMethod::query()->where('category', PaymentCategory::Points)->where('is_active', true)->first()
                ?? throw BusinessException::of(ErrorCode::MemberProgramDisabled);
            $actor = User::query()->findOrFail($locked->user_id);
            $amount = Money::mul((string) MemberSetting::current()->redeem_value, $points);
            $payment = ['id' => (string) Str::uuid7(), 'method' => $method, 'category' => PaymentCategory::Points,
                'amount' => $amount, 'tendered' => $amount, 'change' => '0.00', 'reference' => null];

            $this->writer->addPayments($locked, [$payment], $actor);
            $this->redeem->handle($locked, [$payment]);

            if (Money::compare($locked->paid_total, $locked->grand_total) >= 0) {
                $this->writer->complete($locked, Shift::query()->findOrFail($locked->shift_id), $actor);
            } else {
                $locked->save();
            }

            return $locked;
        });
    }

    /** Poin terbanyak yang boleh dipakai sekarang (0 = tidak bisa: program mati, di bawah minimal, dsb.). */
    public static function maxPoints(Order $order, Member $member): int
    {
        $settings = MemberSetting::current();

        if (! $settings->enabled || ! $member->isUsable() || ! OutletMemberProgram::activeAt($order->outlet_id) || ! Money::isPositive($settings->redeem_value)) {
            return 0;
        }

        $remaining = Money::sub($order->grand_total, $order->paid_total);
        $maxAmount = Money::sub(Money::percentOf($order->grand_total, (string) $settings->max_redeem_percent), Money::mul($settings->redeem_value, (int) $order->points_redeemed));
        $cap = Money::min($remaining, $maxAmount);
        $points = min($member->points_balance, (int) floor((float) bcdiv(Money::max($cap, '0.00'), $settings->redeem_value, 4)));

        return $points >= $settings->min_redeem_points ? $points : 0;
    }
}
