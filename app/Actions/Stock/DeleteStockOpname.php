<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;

/** Hapus DRAF stok opname (izin stock.opname); yang sudah diposting tetap sebagai jejak audit. */
final class DeleteStockOpname
{
    /** @throws BusinessException */
    public function handle(StockOpname $opname): void
    {
        DB::transaction(function () use ($opname): void {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);

            if (! $locked->isDraft()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Stok opname yang sudah diposting tidak bisa dihapus');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }
}
