<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/** Hapus draf PO (ADR 0021); PO yang sudah dikirim dibatalkan, bukan dihapus. */
final class DeletePurchaseOrder
{
    /** @throws BusinessException */
    public function handle(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isDraft()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Hanya draf PO yang bisa dihapus');
            }

            $locked->delete();
        });
    }
}
