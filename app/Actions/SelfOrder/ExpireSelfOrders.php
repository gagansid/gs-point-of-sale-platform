<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\PaymentGatewaySetting;
use App\Models\SelfOrder;
use App\Models\SelfOrderSetting;
use App\Services\Payment\MidtransClient;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kedaluwarsa self-order (ADR 0014, Q68): pesanan menunggu kasir lebih lama dari `payment_timeout_minutes` dan order
 * meja yang sudah diterima tetapi belum dibayar sama sekali dalam batas waktu itu (sejak diterima). Command
 * `pos:expire-self-orders` tiap menit; aman diulang. Order yang sudah dibayar sebagian tidak dikedaluwarsakan.
 */
final class ExpireSelfOrders
{
    /** @return int jumlah pesanan + order yang kedaluwarsa */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        $settings = SelfOrderSetting::allTenants()->get(['tenant_id', 'outlet_id', 'payment_timeout_minutes']);

        foreach ($settings as $setting) {
            $cutoff = $now->subMinutes($setting->payment_timeout_minutes);

            $count += TenantContext::run($setting->tenant_id, fn (): int => DB::transaction(function () use ($setting, $cutoff): int {
                $pending = SelfOrder::query()->where('outlet_id', $setting->outlet_id)->where('status', SelfOrderStatus::Pending)
                    ->where('created_at', '<', $cutoff)->update(['status' => SelfOrderStatus::Expired, 'updated_at' => now()]);

                $ids = Order::query()->where('outlet_id', $setting->outlet_id)->where('channel', OrderChannel::SelfOrder)
                    ->where('status', OrderStatus::AwaitingPayment)->where('accepted_at', '<', $cutoff)->where('paid_total', 0)
                    ->pluck('id');
                Order::query()->whereIn('id', $ids)->update(['status' => OrderStatus::Expired, 'updated_at' => now()]);
                self::cancelOnline($ids->all());

                return $pending + $ids->count();
            }));
        }

        return $count;
    }

    /**
     * Snap yang belum dibayar untuk order kedaluwarsa dibatalkan di Midtrans (gagal diabaikan; Snap juga habis sendiri).
     * Bila pelanggan tetap berhasil membayar, ConfirmOnlinePayment menyelesaikan order.
     *
     * @param  list<string>  $orderIds
     */
    private static function cancelOnline(array $orderIds): void
    {
        $pending = GatewayTransaction::query()->whereIn('order_id', $orderIds)->where('status', GatewayTransaction::PENDING)->get();
        $setting = PaymentGatewaySetting::current();

        foreach ($pending as $transaction) {
            if ($setting->isReady()) {
                MidtransClient::for($setting)->cancel($transaction->id);
            }
            $transaction->forceFill(['status' => 'cancel'])->save();
        }
    }

    /** Pesanan menunggu kasir sudah lewat batas waktu outlet. */
    public static function isPendingExpired(SelfOrder $selfOrder, ?CarbonImmutable $now = null): bool
    {
        $timeout = SelfOrderSetting::forOutlet($selfOrder->outlet_id)->payment_timeout_minutes;

        return $selfOrder->created_at->addMinutes($timeout)->lessThan($now ?? CarbonImmutable::now());
    }

    public function expirePending(SelfOrder $selfOrder): void
    {
        SelfOrder::query()->whereKey($selfOrder->id)->where('status', SelfOrderStatus::Pending)
            ->update(['status' => SelfOrderStatus::Expired, 'updated_at' => now()]);
    }
}
