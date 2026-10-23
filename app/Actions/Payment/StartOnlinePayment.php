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
use App\Services\Payment\MidtransClient;
use App\Support\Features;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Pelanggan membayar pesanan meja lewat Midtrans Snap untuk e-wallet/VA (ADR 0014, Q72; QRIS lewat StartTableQrisPayment,
 * ADR 0027). Nominal = sisa tagihan
 * dari server, dibulatkan ke atas ke rupiah (Midtrans IDR tanpa sen). Transaksi Snap yang masih berlaku dipakai
 * ulang (klik ganda tidak membuat dua tagihan). Lunas hanya lewat webhook terverifikasi.
 */
final class StartOnlinePayment
{
    /** Snap dipakai ulang bila dibuat kurang dari ini (Snap berlaku 30 menit). */
    private const REUSE_MINUTES = 25;

    /** @throws BusinessException */
    public function handle(Order $order, string $customerName, string $finishUrl): GatewayTransaction
    {
        $setting = PaymentGatewaySetting::current();

        // Tanpa fitur paket online_payment (ADR 0025) = belum diaktifkan, pesan ramah untuk pelanggan
        if (! $setting->isReady() || ! Features::enabled('online_payment')) {
            throw BusinessException::of(ErrorCode::PaymentGatewayNotConfigured);
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

        $remaining = Money::sub($order->grand_total, $order->paid_total);
        $amount = (int) ceil((float) $remaining);

        $existing = GatewayTransaction::query()->where('order_id', $order->id)->where('method', GatewayTransaction::SNAP)->where('status', GatewayTransaction::PENDING)
            ->where('amount', $amount)->where('created_at', '>=', now()->subMinutes(self::REUSE_MINUTES))->latest()->first();

        if ($existing !== null && $existing->redirect_url !== null) {
            return $existing;
        }

        $transaction = new GatewayTransaction(['order_id' => $order->id, 'amount' => (string) $amount, 'status' => GatewayTransaction::PENDING]);
        $transaction->id = (string) Str::uuid7();

        $snap = MidtransClient::for($setting)->createSnap($transaction->id, $amount, ['first_name' => mb_substr($customerName, 0, 50)], $finishUrl, self::channels($setting));
        $transaction->fill(['snap_token' => $snap['token'], 'redirect_url' => $snap['redirect_url']])->save();

        return $transaction;
    }

    /**
     * Kanal Snap: e-wallet/VA yang diizinkan owner, tanpa QRIS (QRIS tampil langsung di halaman meja, ADR 0027 K3).
     *
     * @return list<string>
     */
    public static function channels(PaymentGatewaySetting $setting): array
    {
        $allowed = $setting->enabled_payments ?: array_keys(PaymentGatewaySetting::PAYMENT_TYPES);

        return array_values(array_filter($allowed, fn (string $type): bool => $type !== 'qris'));
    }
}
