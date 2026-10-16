<?php

declare(strict_types=1);

namespace App\Actions\Order\Concerns;

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\OpenBillData;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;

/**
 * Bantuan pecah & gabung open bill (ADR 0019): syarat bill, dan item order → isi OpenBillData agar harga dihitung
 * ulang lewat SaveOpenBill (satu jalur perhitungan dengan ubah open bill).
 */
trait OpenBillItems
{
    /** @throws BusinessException */
    private static function ensureSplittable(Order $order, Device $device): void
    {
        CheckoutOrder::ensureAtOutlet($order, $device->outlet_id);

        $problem = match (true) {
            $order->status !== OrderStatus::Open => 'Hanya open bill yang bisa dipecah atau digabung.',
            Money::isPositive($order->paid_total) => 'Open bill '.$order->order_number.' sudah ada pembayaran.',
            $order->voucher_id !== null => 'Lepas voucher di open bill '.$order->order_number.' dulu.',
            ($order->stamp_redemptions ?? []) !== [] => 'Lepas kartu stempel di open bill '.$order->order_number.' dulu.',
            default => null,
        };

        if ($problem !== null) {
            throw BusinessException::of(ErrorCode::ValidationError, $problem, ['order' => [$problem]]);
        }
    }

    /**
     * Diskon item ikut dibagi proporsional qty (revisi 2026-10-16). Item di $movedById = bagian yang TINGGAL setelah
     * sebagian dipindah: diskonnya sisa (diskon − bagian pindahan) agar jumlah kedua bill tetap sama persis.
     *
     * @param  iterable<OrderItem>  $items  item dengan relasi options
     * @param  array<string, int>  $qtyById  qty yang dipakai per item; item yang tidak ada = seluruh qty
     * @param  array<string, int>  $movedById  qty yang dipindah ke bill lain (khusus bill asal saat pecah)
     * @return list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}>
     */
    private static function lines(iterable $items, array $qtyById = [], array $movedById = []): array
    {
        $lines = [];
        foreach ($items as $item) {
            $qty = $qtyById[$item->id] ?? $item->qty;

            if ($qty <= 0) {
                continue;
            }

            $lines[] = [
                'product_id' => $item->product_id,
                'qty' => $qty,
                'option_ids' => $item->options->pluck('option_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                'notes' => $item->notes,
                'discount' => isset($movedById[$item->id])
                    ? Money::sub($item->discount, self::discountShare($item, $movedById[$item->id]))
                    : self::discountShare($item, $qty),
            ];
        }

        return $lines;
    }

    /** Bagian diskon item untuk $qty dari qty-nya (half-up). */
    private static function discountShare(OrderItem $item, int $qty): string
    {
        return $qty >= $item->qty ? $item->discount : Money::div(Money::mul($item->discount, $qty), $item->qty);
    }

    /**
     * Diskon order & approval lama ikut (tanpa PIN ulang): pembagian tidak pernah menambah total diskon.
     *
     * @param  list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}>  $lines
     */
    private static function billData(
        Order $order,
        array $lines,
        ?string $memberId = null,
        bool $memberProvided = false,
        ?string $tableLabel = null,
        ?DiscountType $discountType = null,
        string $discountValue = '0.00',
        ?string $approvedBy = null,
    ): OpenBillData {
        return new OpenBillData(
            orderType: $order->order_type,
            tableLabel: $tableLabel ?? $order->table_label,
            notes: $order->notes,
            discountType: $discountType,
            discountValue: $discountType !== null ? $discountValue : '0.00',
            items: $lines,
            approval: null,
            memberId: $memberId,
            memberProvided: $memberProvided,
            keepApproval: true,
            approvedBy: $approvedBy,
        );
    }
}
