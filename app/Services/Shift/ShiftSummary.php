<?php

declare(strict_types=1);

namespace App\Services\Shift;

use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Shift;
use App\Support\Money;

/**
 * Rekap shift: penjualan per metode bayar dan kas yang seharusnya ada di laci.
 * Hanya order SELESAI yang dihitung (open bill & void tidak).
 *
 * expected_cash = kas awal + penjualan tunai − kembalian = kas awal + Σ amount pembayaran tunai
 * (amount sudah bersih dari kembalian).
 */
final class ShiftSummary
{
    /**
     * @return array{
     *     order_count: int,
     *     open_bill_count: int,
     *     sales_total: string,
     *     payment_methods: list<array{payment_method_id: string, name: string, category: string, count: int, amount: string}>,
     *     opening_cash: string,
     *     cash_sales: string,
     *     expected_cash: string
     * }
     */
    public function for(Shift $shift): array
    {
        $orders = $shift->orders()->where('status', OrderStatus::Completed);

        $payments = Payment::query()
            ->with('method')
            ->where('status', PaymentStatus::Paid)
            ->whereIn('order_id', (clone $orders)->select('id'))
            ->get();

        $byMethod = [];
        foreach ($payments->groupBy('payment_method_id') as $methodId => $group) {
            $first = $group->first();
            $byMethod[] = [
                'payment_method_id' => (string) $methodId,
                'name' => $first?->method->name ?? '-',
                'category' => $first?->category->value ?? '-',
                'count' => $group->count(),
                'amount' => Money::add(...$group->pluck('amount')->all()),
            ];
        }

        $cashSales = Money::add(...$payments->where('category', PaymentCategory::Cash)->pluck('amount')->all());

        return [
            'order_count' => (clone $orders)->count(),
            // Open bill dari shift ini yang belum lunas: terbawa ke shift berikutnya (Q20)
            'open_bill_count' => $shift->orders()->where('status', OrderStatus::Open)->count(),
            'sales_total' => Money::add(...(clone $orders)->pluck('grand_total')->all()),
            'payment_methods' => $byMethod,
            'opening_cash' => $shift->opening_cash,
            'cash_sales' => $cashSales,
            'expected_cash' => Money::add($shift->opening_cash, $cashSales),
        ];
    }
}
