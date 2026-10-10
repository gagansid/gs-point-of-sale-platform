<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Models\PaymentMethod;
use App\Support\Money;

/**
 * Membagi pembayaran ke sisa tagihan (SPEC Pembayaran):
 * - non-tunai dialokasikan dulu dan TIDAK boleh melebihi sisa → PAYMENT_EXCEEDS_BALANCE;
 * - tunai menutup sisanya; kelebihan tunai menjadi kembalian;
 * - metode yang mewajibkan kode approval (debit/kredit) harus mengisi reference;
 * - metode harus aktif di bisnis dan di outlet order (ADR 0011 / Q44).
 */
final class AllocatePayments
{
    /**
     * @param  list<array{id: string, payment_method_id: string, amount: string, tendered: string|null, reference: string|null}>  $payments
     * @return list<array{id: string, method: PaymentMethod, category: PaymentCategory, amount: string, tendered: string, change: string, reference: string|null}>
     *
     * @throws BusinessException
     */
    public function handle(array $payments, string $remaining, string $outletId): array
    {
        $methods = PaymentMethod::query()->activeAt($outletId)
            ->whereIn('id', array_column($payments, 'payment_method_id'))
            ->get()
            ->keyBy('id');

        $errors = [];
        foreach ($payments as $index => $payment) {
            $method = $methods->get($payment['payment_method_id']);

            if ($method === null) {
                $errors["payments.{$index}.payment_method_id"] = ['Metode bayar tidak tersedia'];
            } elseif ($method->requires_reference && $payment['reference'] === null) {
                $errors["payments.{$index}.reference"] = ["Kode approval {$method->name} wajib diisi"];
            }
        }

        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, details: $errors);
        }

        // Non-tunai dulu (nominal pas), lalu tunai (menutup sisa + kembalian). Urutan asli dipertahankan di hasil.
        $order = array_keys($payments);
        usort($order, fn (int $a, int $b): int => ($methods[$payments[$a]['payment_method_id']]->category === PaymentCategory::Cash) <=> ($methods[$payments[$b]['payment_method_id']]->category === PaymentCategory::Cash));

        $allocated = [];
        foreach ($order as $index) {
            $payment = $payments[$index];
            $method = $methods[$payment['payment_method_id']];

            if ($method->category === PaymentCategory::Cash) {
                $tendered = $payment['tendered'] ?? $payment['amount'];
                $amount = Money::min($tendered, $remaining);

                if (! Money::isPositive($amount)) {
                    throw BusinessException::of(ErrorCode::ValidationError, 'Pembayaran tunai tidak diperlukan', [
                        "payments.{$index}.amount" => ['Tagihan sudah lunas tanpa pembayaran tunai ini'],
                    ]);
                }
            } else {
                $amount = $payment['amount'];
                $tendered = $amount;

                if (Money::compare($amount, $remaining) > 0) {
                    throw BusinessException::of(ErrorCode::PaymentExceedsBalance, details: [
                        'payment_index' => $index,
                        'remaining' => $remaining,
                    ]);
                }
            }

            $remaining = Money::sub($remaining, $amount);
            $allocated[$index] = [
                'id' => $payment['id'],
                'method' => $method,
                'category' => $method->category,
                'amount' => $amount,
                'tendered' => $tendered,
                'change' => Money::sub($tendered, $amount),
                'reference' => $payment['reference'],
            ];
        }

        ksort($allocated);

        return array_values($allocated);
    }
}
