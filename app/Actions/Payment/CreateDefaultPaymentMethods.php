<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\PaymentCategory;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Metode bayar bawaan tenant baru: Tunai, QRIS, Transfer, Debit, Kredit (SPEC Cakupan MVP).
 * Aman dijalankan ulang: kategori yang sudah ada dilewati. Harus dalam tenant context.
 */
final class CreateDefaultPaymentMethods
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            foreach (PaymentCategory::cases() as $order => $category) {
                PaymentMethod::query()->firstOrCreate(['category' => $category], [
                    'name' => $category->getLabel(),
                    'requires_reference' => $category->requiresReferenceByDefault(),
                    'is_active' => true,
                    'sort_order' => $order,
                ]);
            }
        });
    }
}
