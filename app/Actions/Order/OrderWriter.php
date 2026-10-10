<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Data\ResolvedLine;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Order\CalculationResult;
use App\Support\Money;

/**
 * Penulisan bagian order yang dipakai bersama checkout, open bill, tambah pembayaran, dan void.
 * Semua metode dipanggil DI DALAM transaksi milik Action pemanggil.
 */
final class OrderWriter
{
    /** Menyalin hasil OrderCalculator & setelan outlet ke order (nominal hanya lewat sini). */
    public function applyTotals(Order $order, CalculationResult $totals, Outlet $outlet): void
    {
        $order->forceFill([
            'order_discount' => $totals->orderDiscount,
            'subtotal' => $totals->subtotal,
            'discount_total' => $totals->discountTotal,
            'service_total' => $totals->serviceTotal,
            'tax_total' => $totals->taxTotal,
            'rounding' => $totals->rounding,
            'grand_total' => $totals->grandTotal,
            'service_rate' => $outlet->service_charge_rate,
            'tax_rate' => $outlet->tax_rate,
            'tax_inclusive' => $outlet->tax_inclusive,
        ]);
    }

    /**
     * Mengganti seluruh item order (snapshot nama & harga dari katalog saat ini).
     *
     * @param  list<ResolvedLine>  $lines
     */
    public function replaceItems(Order $order, array $lines, CalculationResult $totals): void
    {
        $order->items()->delete();

        foreach ($lines as $position => $line) {
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $line->product->id,
                'product_name' => $line->product->name,
                'unit_price' => $line->product->price,
                'options_total' => $line->optionsTotal,
                'qty' => $line->qty,
                'discount' => $totals->lines[$position]['discount'],
                'line_total' => $totals->lines[$position]['line_total'],
                'notes' => $line->notes,
                'sort_order' => $position,
            ]);

            foreach ($line->options as $option) {
                OrderItemOption::query()->create([
                    'order_item_id' => $item->id,
                    'option_id' => $option->id,
                    'option_name' => $option->name,
                    'price_delta' => $option->price_delta,
                ]);
            }
        }
    }

    /**
     * @param  list<array{id: string, method: PaymentMethod, category: PaymentCategory, amount: string, tendered: string, change: string, reference: string|null}>  $payments
     */
    public function addPayments(Order $order, array $payments, User $actor): void
    {
        foreach ($payments as $payment) {
            $model = new Payment([
                'order_id' => $order->id,
                'payment_method_id' => $payment['method']->id,
                'user_id' => $actor->id,
                'category' => $payment['category'],
                'amount' => $payment['amount'],
                'tendered' => $payment['tendered'],
                'change' => $payment['change'],
                'reference' => $payment['reference'],
                'status' => PaymentStatus::Paid,
            ]);
            $model->id = $payment['id'];
            $model->save();
        }

        $paid = $order->payments()->where('status', PaymentStatus::Paid)->get();
        $order->forceFill([
            'paid_total' => Money::add(...$paid->pluck('amount')->all()),
            'change_total' => Money::add(...$paid->pluck('change')->all()),
        ]);
    }

    /**
     * Menyelesaikan order: status selesai, pindah ke shift penerima uang (Q20), stok dipotong.
     */
    public function complete(Order $order, Shift $shift, User $actor): void
    {
        $order->forceFill([
            'status' => OrderStatus::Completed,
            'shift_id' => $shift->id,
            'completed_at' => now(),
        ])->save();

        $this->moveStock($order, $actor, StockMovementType::Sale);
    }

    /**
     * Mengubah stok outlet order untuk produk ber-track_stock (jual = kurang, void = kembali).
     * Baris stok dikunci: transaksi bersamaan tidak saling menimpa. Stok boleh minus (SPEC).
     */
    public function moveStock(Order $order, ?User $actor, StockMovementType $type): void
    {
        $sign = $type === StockMovementType::Sale ? -1 : 1;

        foreach ($order->items()->get() as $item) {
            $product = Product::query()->withTrashed()->find($item->product_id);

            if ($product === null || ! $product->track_stock) {
                continue;
            }

            $stock = ProductStock::for($product->id, $order->outlet_id, lock: true);
            $change = $sign * $item->qty;
            $stock->stock_qty += $change;
            $stock->save();

            StockMovement::query()->create([
                'outlet_id' => $order->outlet_id,
                'product_id' => $product->id,
                'user_id' => $actor?->id,
                'type' => $type,
                'qty_change' => $change,
                'qty_after' => $stock->stock_qty,
                'reference_id' => $order->id,
            ]);
        }
    }
}
