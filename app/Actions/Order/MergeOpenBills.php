<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Concerns\OpenBillItems;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gabung open bill (ADR 0019, POST /orders/{id}/merge): semua item bill sumber ditambahkan ke bill tujuan (dihitung
 * ulang), bill sumber berstatus merged. Gabung ulang yang sama = hasil sama (idempotent).
 */
final class MergeOpenBills
{
    use OpenBillItems;

    public function __construct(private readonly SaveOpenBill $save) {}

    /**
     * @return array{order: Order, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, Order $target, Order $source): array
    {
        CheckoutOrder::ensureAtOutlet($source, $device->outlet_id);

        if ($source->status === OrderStatus::Merged && $source->merged_into_id === $target->id) {
            return ['order' => CheckoutOrder::load($target->refresh()), 'replayed' => true];
        }

        if ($source->is($target)) {
            throw self::invalid('Pilih open bill lain untuk digabung.');
        }

        $order = DB::transaction(function () use ($actor, $device, $target, $source): Order {
            // Kunci berurutan menurut ID agar dua penggabungan silang tidak saling menunggu
            $ids = collect([$target->id, $source->id])->sort()->values();
            $locked = Order::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedTarget = $locked[$target->id];
            $lockedSource = $locked[$source->id];
            self::ensureSplittable($lockedTarget, $device);
            self::ensureSplittable($lockedSource, $device);

            if ($lockedTarget->member_id !== null && $lockedSource->member_id !== null && $lockedTarget->member_id !== $lockedSource->member_id) {
                throw self::invalid('Kedua open bill memakai member berbeda. Lepas salah satu member dulu.');
            }

            $memberId = $lockedTarget->member_id ?? $lockedSource->member_id;
            $items = [
                ...self::lines($lockedTarget->items()->with('options')->get()),
                ...self::lines($lockedSource->items()->with('options')->get()),
            ];

            [$type, $value] = self::mergedDiscount($lockedTarget, $lockedSource);
            $merged = $this->save->handle($actor, $device, $lockedTarget->id, self::billData(
                $lockedTarget, $items, $memberId, $lockedTarget->member_id === null && $memberId !== null,
                discountType: $type, discountValue: $value, approvedBy: $lockedTarget->approved_by ?? $lockedSource->approved_by,
            ))['order'];
            $lockedSource->forceFill(['status' => OrderStatus::Merged, 'merged_into_id' => $lockedTarget->id])->save();

            return $merged;
        });

        Log::info('Open bill digabung', ['order_id' => $target->id, 'merged_order_id' => $source->id, 'user_id' => $actor->id]);

        return ['order' => $order, 'replayed' => false];
    }

    /**
     * Diskon order hasil gabung: persen sama di kedua bill → tetap persen; selain itu nominal = jumlah potongan order
     * kedua bill (persen tidak diperluas ke item bill lain, jadi total diskon tidak bertambah).
     *
     * @return array{0: DiscountType|null, 1: string}
     */
    private static function mergedDiscount(Order $target, Order $source): array
    {
        if ($target->discount_type === null && $source->discount_type === null) {
            return [null, '0.00'];
        }

        if ($target->discount_type === DiscountType::Percent && $source->discount_type === DiscountType::Percent
            && Money::compare($target->discount_value ?? '0.00', $source->discount_value ?? '0.00') === 0) {
            return [DiscountType::Percent, $target->discount_value ?? '0.00'];
        }

        $amount = Money::add($target->order_discount, $source->order_discount);

        return Money::isPositive($amount) ? [DiscountType::Fixed, $amount] : [null, '0.00'];
    }

    private static function invalid(string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, ['order' => [$message]]);
    }
}
