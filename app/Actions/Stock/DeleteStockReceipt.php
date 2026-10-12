<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\StockReceipt;
use Illuminate\Support\Facades\DB;

/** Hapus DRAF stok masuk (izin stock.receive). Yang sudah diposting dibatalkan, bukan dihapus (jejak audit). */
final class DeleteStockReceipt
{
    /** @throws BusinessException */
    public function handle(StockReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $locked = StockReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if (! $locked->isDraft()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Hanya draf yang bisa dihapus; batalkan stok masuk yang sudah diposting');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }
}
