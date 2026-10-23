<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\SelfOrderSetting;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Selesai otomatis (ADR 0027 K4): pesanan meja lunas yang belum ditandai Diantar dalam `auto_complete_minutes` sejak
 * lunas (setelan outlet, bawaan 60) ditutup sebagai *Selesai otomatis* (`fulfillment_auto`, bukan oleh staf). Command
 * `pos:auto-complete-fulfillment` tiap 5 menit; aman diulang; tidak menyentuh order belum lunas/void/sudah diantar.
 * Setiap order yang ditutup dicatat di log aktivitas.
 */
final class AutoCompleteFulfillment
{
    /** @return int jumlah pesanan yang ditutup */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        foreach (SelfOrderSetting::allTenants()->get(['tenant_id', 'outlet_id', 'auto_complete_minutes']) as $setting) {
            $cutoff = $now->subMinutes(max(30, (int) $setting->auto_complete_minutes));

            $count += TenantContext::run($setting->tenant_id, function () use ($setting, $cutoff, $now): int {
                $orders = Order::query()->where('outlet_id', $setting->outlet_id)->where('channel', OrderChannel::SelfOrder)
                    ->where('status', OrderStatus::Completed)->whereIn('fulfillment_status', FulfillmentStatus::open())
                    ->where('completed_at', '<', $cutoff)->limit(500)->get();
                $closed = 0;

                foreach ($orders as $order) {
                    // Bersyarat pada tahap lama: staf yang baru saja menandai Diantar tidak tertimpa
                    $updated = Order::query()->whereKey($order->id)->whereIn('fulfillment_status', FulfillmentStatus::open())->update([
                        'fulfillment_status' => FulfillmentStatus::AutoCompleted,
                        'fulfillment_previous' => null,
                        'fulfillment_auto' => true,
                        'served_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($updated === 1) {
                        $closed++;
                        activity('fulfillment')->performedOn($order)->event('auto_completed')
                            ->withProperties(['from' => $order->fulfillment_status?->value, 'minutes' => $setting->auto_complete_minutes])
                            ->log('Pesanan meja '.$order->order_number.' selesai otomatis');
                    }
                }

                return $closed;
            });
        }

        return $count;
    }
}
