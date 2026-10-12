<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Hapus DRAF transfer (pemegang outlet asal); yang sudah dikirim dibatalkan, bukan dihapus. */
final class DeleteStockTransfer
{
    /** @throws BusinessException */
    public function handle(StockTransfer $transfer, User $by): void
    {
        if (! $by->canAccessOutlet($transfer->from_outlet_id)) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        DB::transaction(function () use ($transfer): void {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! $locked->isDraft()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Hanya draf yang bisa dihapus; batalkan transfer yang sedang dikirim');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }
}
