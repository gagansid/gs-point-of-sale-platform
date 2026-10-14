<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Kirim PO (ADR 0021): draf → dikirim, isi terkunci. Kirim ulang = hasil sama. */
final class SendPurchaseOrder
{
    /** @throws BusinessException */
    public function handle(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isDraft()) {
                return $locked;
            }

            if ($locked->items()->doesntExist()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'PO belum berisi item', ['items' => ['Tambahkan minimal satu item']]);
            }

            $locked->forceFill(['status' => PurchaseOrderStatus::Ordered, 'sent_at' => now(), 'sent_by' => $by->id])->save();

            return $locked;
        });
    }
}
