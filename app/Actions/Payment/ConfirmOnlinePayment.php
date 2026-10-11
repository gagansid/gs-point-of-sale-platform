<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Order\OrderWriter;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pembayaran online terverifikasi (ADR 0014, Q72–Q73): pembayaran kategori `online` dicatat atas nama kasir yang
 * menerima pesanan, order selesai di shift penerima (stok dipotong, poin/stempel/voucher diproses). Idempotent per
 * transaksi. Order sudah lunas/di-void saat dana masuk → "perlu refund manual" (tidak ada refund otomatis).
 * Order kedaluwarsa tetapi dibayar → tetap diselesaikan (pelanggan sudah membayar).
 */
final class ConfirmOnlinePayment
{
    public function __construct(private readonly OrderWriter $writer) {}

    /** @param  array{transaction_id?: string|null, payment_type?: string|null}  $status */
    public function handle(GatewayTransaction $transaction, array $status): GatewayTransaction
    {
        return DB::transaction(function () use ($transaction, $status): GatewayTransaction {
            $locked = GatewayTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($locked->payment_id !== null || $locked->status === GatewayTransaction::REFUND_REQUIRED) {
                return $locked;
            }

            $order = Order::query()->withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->lockForUpdate()->findOrFail($locked->order_id);
            $locked->fill(['transaction_id' => $status['transaction_id'] ?? null, 'payment_type' => $status['payment_type'] ?? null]);

            if ($order->status === OrderStatus::Expired) {
                $order->forceFill(['status' => OrderStatus::AwaitingPayment])->save();
            }

            if ($order->status !== OrderStatus::AwaitingPayment) {
                $locked->forceFill(['status' => GatewayTransaction::REFUND_REQUIRED, 'settled_at' => now()])->save();
                $order->forceFill(['refund_required' => true])->save();
                Log::warning('Pembayaran online masuk untuk order yang sudah ditutup — perlu refund manual', ['order_id' => $order->id, 'transaction' => $locked->id]);

                return $locked;
            }

            $method = PaymentMethod::query()->where('category', PaymentCategory::Online)->firstOrFail();
            $actor = User::query()->findOrFail($order->user_id);
            $remaining = Money::sub($order->grand_total, $order->paid_total);
            $paymentId = (string) Str::uuid7();

            $this->writer->addPayments($order, [[
                'id' => $paymentId,
                'method' => $method,
                'category' => PaymentCategory::Online,
                'amount' => $remaining,
                'tendered' => $remaining,
                'change' => '0.00',
                'reference' => mb_substr((string) ($status['transaction_id'] ?? $locked->id), 0, 50),
            ]], $actor);
            $this->writer->complete($order, Shift::query()->withoutGlobalScopes()->findOrFail($order->shift_id), $actor);

            $locked->forceFill(['status' => GatewayTransaction::SETTLEMENT, 'payment_id' => $paymentId, 'settled_at' => now()])->save();

            return $locked;
        });
    }
}
