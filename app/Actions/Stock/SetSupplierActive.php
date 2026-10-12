<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Models\Supplier;

/** Aktif/nonaktifkan supplier (izin supplier.manage); nonaktif = tidak bisa dipilih di stok masuk baru. */
final class SetSupplierActive
{
    public function handle(Supplier $supplier, bool $active): Supplier
    {
        $supplier->forceFill(['is_active' => $active])->save();

        return $supplier;
    }
}
