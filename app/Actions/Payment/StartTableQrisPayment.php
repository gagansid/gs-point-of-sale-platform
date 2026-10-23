<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\ErrorCode;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\PaymentGatewaySetting;
use App\Models\SelfOrderSetting;
use App\Services\Payment\MidtransClient;
use App\Support\Features;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * QRIS di halaman meja (ADR 0027 K3): Midtrans Core API `qris`, nominal = sisa tagihan server dibulatkan ke atas ke
 * rupiah. Satu QR aktif per order: QR yang masih berlaku dipakai ulang (klik ganda = QR sama); buat baru (`$renew` atau QR
 * lama kedaluwarsa) membatalkan QR pending lama di Midtrans. Batas membuat QR: 5/10 menit per order, 10/10 menit per IP.
 * Masa berlaku QR = maks. 15 menit dan tidak melewati batas waktu bayar order. Lunas hanya lewat webhook terverifikasi.
 */
final class StartTableQrisPayment
{
    public const MAX_MINUTES = 15;

    public const PER_ORDER = 5;

    public const PER_IP = 10;

    private const WINDOW_SECONDS = 600;

    /** @throws BusinessException PAYMENT_GATEWAY_NOT_CONFIGURED, NOT_FOUND, ORDER_EXPIRED, ORDER_ALREADY_CLOSED, TOO_MANY_REQUESTS, PAYMENT_GATEWAY_ERROR */
    public function handle(Order $order, string $ip, bool $renew = false): GatewayTransaction
    {
        $setting = PaymentGatewaySetting::current();

        if (! self::available($setting, SelfOrderSetting::forOutlet($order->outlet_id))) {
            throw BusinessException::of(ErrorCode::PaymentGatewayNotConfigured, 'QRIS belum tersedia di outlet ini. Silakan bayar di kasir');
        }

        if ($order->channel !== OrderChannel::SelfOrder) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        if ($order->status === OrderStatus::Expired) {
            throw BusinessException::of(ErrorCode::OrderExpired);
        }

        if ($order->status !== OrderStatus::AwaitingPayment) {
            throw BusinessException::of(ErrorCode::OrderAlreadyClosed);
        }

        $amount = (int) ceil((float) Money::sub($order->grand_total, $order->paid_total));
        $pending = GatewayTransaction::query()->where('order_id', $order->id)->where('method', GatewayTransaction::QRIS)
            ->where('status', GatewayTransaction::PENDING)->latest()->get();

        $active = $pending->first(fn (GatewayTransaction $t): bool => $t->isQrActive() && (int) $t->amount === $amount);
        if ($active !== null && ! $renew) {
            return $active;
        }

        $minutes = self::minutesLeft($order);
        if ($minutes < 1) {
            throw BusinessException::of(ErrorCode::OrderExpired, 'Waktu bayar pesanan ini sudah habis');
        }

        foreach (['qris-order:'.$order->id => self::PER_ORDER, 'qris-ip:'.$ip => self::PER_IP] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw BusinessException::of(ErrorCode::TooManyRequests, 'Terlalu sering membuat QR. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit atau bayar di kasir');
            }
        }

        $client = MidtransClient::for($setting);
        // Satu QR aktif: QR lama yang belum dibayar dibatalkan (bila ternyata dibayar, webhook tetap menyelesaikan order)
        foreach ($pending as $old) {
            $client->cancel($old->id);
            $old->forceFill(['status' => 'cancel'])->save();
        }

        $transaction = new GatewayTransaction(['order_id' => $order->id, 'amount' => (string) $amount, 'status' => GatewayTransaction::PENDING, 'method' => GatewayTransaction::QRIS]);
        $transaction->id = (string) Str::uuid7();

        foreach (['qris-order:'.$order->id, 'qris-ip:'.$ip] as $key) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }

        $qris = $client->chargeQris($transaction->id, $amount, $minutes);
        $transaction->fill([
            'qr_string' => $qris['qr_string'],
            'transaction_id' => $qris['transaction_id'],
            'expires_at' => $qris['expires_at'],
            'payment_type' => 'qris',
        ])->save();

        return $transaction;
    }

    /** QRIS bisa ditawarkan: Midtrans siap, fitur paket, `qris` diizinkan owner, dan diizinkan di outlet. */
    public static function available(PaymentGatewaySetting $gateway, SelfOrderSetting $outlet): bool
    {
        $allowed = $gateway->enabled_payments;

        return $gateway->isReady() && Features::enabled('online_payment') && $outlet->allow_qris
            && ($allowed === null || $allowed === [] || in_array('qris', $allowed, true));
    }

    /** Sisa menit sebelum order kedaluwarsa (batas waktu bayar sejak checkout), maks. 15. */
    private static function minutesLeft(Order $order): int
    {
        $timeout = SelfOrderSetting::forOutlet($order->outlet_id)->payment_timeout_minutes;
        $deadline = CarbonImmutable::parse($order->accepted_at ?? $order->created_at)->addMinutes($timeout);

        return (int) min(self::MAX_MINUTES, floor(CarbonImmutable::now()->diffInSeconds($deadline, false) / 60));
    }
}
