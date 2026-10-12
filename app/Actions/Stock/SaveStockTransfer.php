<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Stock\DocumentNumberGenerator;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Buat/ubah DRAF transfer (ADR 0015, Q82; izin stock.transfer). Pembuat wajib memegang outlet asal; tujuan = outlet
 * aktif lain di bisnis yang sama. Item = produk lacak stok, qty bulat > 0; produk sama digabung. `id` = idempotency key.
 */
final class SaveStockTransfer
{
    public const MAX_ITEMS = 200;

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data  id (baru), from_outlet_id, to_outlet_id, notes, items[{product_id, qty}]
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?StockTransfer $transfer, User $by, array $data): StockTransfer
    {
        if ($transfer === null && filled($data['id'] ?? null) && ($existing = Idempotency::existing(StockTransfer::class, (string) $data['id'])) !== null) {
            return $existing;
        }

        if ($transfer !== null && ! $transfer->isDraft()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Transfer yang sudah dikirim tidak bisa diubah');
        }

        $validated = Validator::make($data, [
            'id' => ['nullable', 'uuid'],
            'from_outlet_id' => ['required', 'uuid'],
            'to_outlet_id' => ['required', 'uuid', 'different:from_outlet_id'],
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ], ['to_outlet_id.different' => 'Outlet tujuan harus berbeda dari outlet asal'], [
            'from_outlet_id' => 'outlet asal', 'to_outlet_id' => 'outlet tujuan', 'items' => 'item', 'items.*.qty' => 'qty',
        ])->validate();

        $from = Outlet::query()->active()->find($validated['from_outlet_id']);
        $to = Outlet::query()->active()->find($validated['to_outlet_id']);

        if ($from === null || ! $by->canAccessOutlet($from->id)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Outlet asal tidak ditemukan atau bukan outlet Anda', ['from_outlet_id' => ['Pilih outlet asal yang Anda pegang']]);
        }

        if ($to === null) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Outlet tujuan tidak ditemukan atau nonaktif', ['to_outlet_id' => ['Outlet tujuan tidak ditemukan atau nonaktif']]);
        }

        // Produk sama digabung
        $qtys = [];
        foreach ($validated['items'] as $item) {
            $qtys[$item['product_id']] = ($qtys[$item['product_id']] ?? 0) + (int) $item['qty'];
        }

        $products = Product::query()->whereIn('id', array_keys($qtys))->get()->keyBy('id');
        foreach (array_keys($qtys) as $productId) {
            $product = $products->get($productId);
            if ($product === null || ! $product->track_stock) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Produk tidak ditemukan atau tidak melacak stok', ['items' => [($product->name ?? 'Produk').' tidak bisa ditransfer']]);
            }
        }

        return DB::transaction(function () use ($transfer, $from, $to, $validated, $qtys, $products): StockTransfer {
            if ($transfer === null) {
                $transfer = new StockTransfer;
                if (filled($validated['id'] ?? null)) {
                    $transfer->id = (string) $validated['id'];
                }
                $transfer->forceFill(['number' => $this->numbers->next($from, DocumentNumberGenerator::TRANSFER)]);
            } else {
                $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
                if (! $transfer->isDraft()) {
                    throw BusinessException::of(ErrorCode::ValidationError, 'Transfer yang sudah dikirim tidak bisa diubah');
                }
                // Nomor mengikuti outlet asal saat dibuat; asal tidak diganti setelah bernomor
                if ($transfer->from_outlet_id !== $from->id) {
                    throw BusinessException::of(ErrorCode::ValidationError, 'Outlet asal draf tidak bisa diganti; buat transfer baru', ['from_outlet_id' => ['Outlet asal tidak bisa diganti']]);
                }
            }

            $transfer->fill([
                'from_outlet_id' => $from->id,
                'to_outlet_id' => $to->id,
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            ])->save();

            $transfer->items()->delete();
            $position = 0;
            foreach ($qtys as $productId => $qty) {
                StockTransferItem::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $productId,
                    'product_name' => $products[$productId]->name,
                    'qty_sent' => $qty,
                    'sort_order' => $position++,
                ]);
            }

            return $transfer->load('items');
        });
    }
}
