<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\Stock\DocumentNumberGenerator;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Buat lembar stok opname (ADR 0015, Q81; izin stock.opname): semua produk lacak stok yang dijual di outlet (atau satu
 * kategori), dengan stok sistem saat ini sebagai patokan. `id` dari klien = idempotency key.
 */
final class CreateStockOpname
{
    public const MAX_ITEMS = 1000;

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data  id, category_id, counted_on (Y-m-d), notes
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(Outlet $outlet, array $data): StockOpname
    {
        if (filled($data['id'] ?? null) && ($existing = Idempotency::existing(StockOpname::class, (string) $data['id'])) !== null) {
            return $existing;
        }

        $validated = Validator::make($data, [
            'id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'counted_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['counted_on' => 'tanggal hitung', 'notes' => 'catatan'])->validate();

        if ($validated['counted_on'] > $outlet->localDate()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tanggal hitung tidak boleh di masa depan', ['counted_on' => ['Tanggal hitung tidak boleh di masa depan']]);
        }

        $categoryId = $validated['category_id'] ?? null;
        if ($categoryId !== null && ! Category::query()->whereKey($categoryId)->exists()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Kategori tidak ditemukan', ['category_id' => ['Kategori tidak ditemukan']]);
        }

        $products = Product::query()->where('track_stock', true)->listedAt($outlet->id)
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')->get(['id', 'name']);

        if ($products->isEmpty()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tidak ada produk lacak stok untuk dihitung', ['category_id' => ['Tidak ada produk lacak stok untuk dihitung']]);
        }

        if ($products->count() > self::MAX_ITEMS) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.self::MAX_ITEMS.' produk per lembar; pilih kategori', ['category_id' => ['Pilih kategori agar lembar lebih kecil']]);
        }

        $stocks = ProductStock::query()->where('outlet_id', $outlet->id)->whereIn('product_id', $products->pluck('id'))->pluck('stock_qty', 'product_id');

        return DB::transaction(function () use ($outlet, $validated, $categoryId, $products, $stocks): StockOpname {
            $opname = new StockOpname([
                'outlet_id' => $outlet->id,
                'category_id' => $categoryId,
                'counted_on' => $validated['counted_on'],
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            ]);
            if (filled($validated['id'] ?? null)) {
                $opname->id = (string) $validated['id'];
            }
            $opname->forceFill(['number' => $this->numbers->next($outlet, DocumentNumberGenerator::OPNAME)])->save();

            foreach ($products->values() as $position => $product) {
                StockOpnameItem::query()->create([
                    'stock_opname_id' => $opname->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'system_qty' => (int) ($stocks[$product->id] ?? 0),
                    'sort_order' => $position,
                ]);
            }

            return $opname->load('items');
        });
    }
}
