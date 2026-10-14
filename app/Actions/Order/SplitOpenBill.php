<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Concerns\OpenBillItems;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\User;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pecah open bill (ADR 0019, POST /orders/{id}/split): item & qty pindah ke open bill baru (ID dari klien), kedua bill
 * dihitung ulang. Bill asal minimal tersisa 1 item. Member tetap di bill asal.
 */
final class SplitOpenBill
{
    use OpenBillItems;

    public function __construct(private readonly SaveOpenBill $save) {}

    /**
     * @param  array<string, int>  $moves  order_item_id => qty dipindah
     * @return array{source: Order, target: Order, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, Order $order, string $newOrderId, array $moves, ?string $tableLabel = null): array
    {
        // Kiriman ulang: bill baru sudah dibuat dari pecahan ini
        if (($existing = Idempotency::existing(Order::class, $newOrderId, fn (Order $o): bool => $o->outlet_id === $device->outlet_id)) !== null) {
            return ['source' => CheckoutOrder::load($order->refresh()), 'target' => CheckoutOrder::load($existing), 'replayed' => true];
        }

        self::ensureSplittable($order, $device);
        $moves = array_filter($moves, fn (int $qty): bool => $qty > 0);

        if ($moves === []) {
            throw self::invalid('Pilih minimal satu item yang dipindah.');
        }

        $result = DB::transaction(function () use ($actor, $device, $order, $newOrderId, $moves, $tableLabel): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            self::ensureSplittable($locked, $device);
            $items = $locked->items()->with('options')->get();
            $remaining = [];

            foreach ($moves as $itemId => $qty) {
                $item = $items->firstWhere('id', (string) $itemId) ?? throw self::invalid('Item tidak ada di open bill ini.');

                if ($qty > $item->qty) {
                    throw self::invalid("{$item->product_name}: maksimal {$item->qty}.");
                }
            }

            foreach ($items as $item) {
                $remaining[$item->id] = $item->qty - ($moves[$item->id] ?? 0);
            }

            if (array_sum($remaining) === 0) {
                throw self::invalid('Sisakan minimal satu item di open bill asal (untuk pindah semua, ganti nomor meja).');
            }

            $moved = $items->filter(fn ($item): bool => isset($moves[$item->id]));
            $target = $this->save->handle($actor, $device, $newOrderId, self::billData($locked, self::lines($moved, $moves), tableLabel: $tableLabel))['order'];
            $source = $this->save->handle($actor, $device, $locked->id, self::billData($locked, self::lines($items, $remaining)))['order'];

            return ['source' => $source, 'target' => $target];
        });

        Log::info('Open bill dipecah', ['order_id' => $order->id, 'new_order_id' => $newOrderId, 'user_id' => $actor->id]);

        return [...$result, 'replayed' => false];
    }

    private static function invalid(string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, ['items' => [$message]]);
    }
}
