<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Support\Facades\DB;

/**
 * Simpan hitungan fisik di lembar opname DRAF (ADR 0015, Q81; izin stock.opname). Kosong = belum/tidak dihitung.
 * Hanya baris milik lembar ini yang diubah.
 */
final class SaveOpnameCounts
{
    /**
     * @param  array<string, int|string|null>  $counts  item_id => qty fisik (≥ 0) atau null
     *
     * @throws BusinessException
     */
    public function handle(StockOpname $opname, array $counts, ?string $notes = null): StockOpname
    {
        $errors = [];
        foreach ($counts as $itemId => $qty) {
            if ($qty !== null && $qty !== '' && (! is_numeric($qty) || (int) $qty != $qty || (int) $qty < 0 || (int) $qty > 1000000)) {
                $errors["counts.{$itemId}"] = ['Qty fisik harus bilangan bulat 0–1.000.000'];
            }
        }
        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Qty fisik harus bilangan bulat 0 atau lebih', $errors);
        }

        return DB::transaction(function () use ($opname, $counts, $notes): StockOpname {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);

            if (! $locked->isDraft()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Stok opname yang sudah diposting tidak bisa diubah');
            }

            $items = StockOpnameItem::query()->where('stock_opname_id', $locked->id)->whereIn('id', array_keys($counts))->get();
            foreach ($items as $item) {
                $qty = $counts[$item->id];
                $item->forceFill(['counted_qty' => $qty === null || $qty === '' ? null : (int) $qty])->save();
            }

            if ($notes !== null) {
                $locked->forceFill(['notes' => trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null])->save();
            }

            return $locked->load('items');
        });
    }
}
