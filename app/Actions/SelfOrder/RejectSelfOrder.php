<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Enums\ErrorCode;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\SelfOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Kasir menolak pesanan meja dengan alasan (ADR 0014); alasan tampil di HP pelanggan. */
final class RejectSelfOrder
{
    /** @throws BusinessException */
    public function handle(User $actor, string $outletId, SelfOrder $selfOrder, string $reason): SelfOrder
    {
        if (! $actor->can('self_order.handle')) {
            throw BusinessException::of(ErrorCode::Forbidden);
        }

        if ($selfOrder->outlet_id !== $outletId) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan wajib diisi (maks. 255 karakter)', ['reason' => ['Alasan wajib diisi (maks. 255 karakter)']]);
        }

        return DB::transaction(function () use ($actor, $selfOrder, $reason): SelfOrder {
            $locked = SelfOrder::query()->lockForUpdate()->findOrFail($selfOrder->id);
            AcceptSelfOrder::ensurePending($locked);

            $locked->forceFill([
                'status' => SelfOrderStatus::Rejected,
                'reject_reason' => $reason,
                'handled_by' => $actor->id,
                'handled_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
