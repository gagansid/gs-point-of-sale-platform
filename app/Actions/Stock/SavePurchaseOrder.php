<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Stock\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Buat/ubah DRAF purchase order (ADR 0021; izin purchase.manage). Supplier aktif wajib; item hanya produk lacak stok
 * milik bisnis; item menggantikan item lama. Produk sama dalam satu PO digabung.
 */
final class SavePurchaseOrder
{
    public const MAX_ITEMS = 200;

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data  supplier_id, ordered_on, expected_on, notes, items[{product_id, qty, unit_cost}]
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?PurchaseOrder $order, Outlet $outlet, array $data): PurchaseOrder
    {
        if ($order !== null && ! $order->isDraft()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'PO yang sudah dikirim tidak bisa diubah');
        }

        $validated = Validator::make($data, self::rules(), [], [
            'supplier_id' => 'supplier', 'ordered_on' => 'tanggal pesan', 'expected_on' => 'perkiraan datang', 'notes' => 'catatan',
            'items' => 'item', 'items.*.product_id' => 'produk', 'items.*.qty' => 'qty', 'items.*.unit_cost' => 'harga beli',
        ])->validate();

        if (! Supplier::query()->whereKey($validated['supplier_id'])->where('is_active', true)->exists()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Supplier tidak ditemukan atau nonaktif', ['supplier_id' => ['Supplier tidak ditemukan atau nonaktif']]);
        }

        // Produk sama digabung (qty dijumlah, harga baris terakhir)
        $lines = [];
        foreach ($validated['items'] as $item) {
            $id = (string) $item['product_id'];
            $lines[$id] = ['qty' => ($lines[$id]['qty'] ?? 0) + (int) $item['qty'], 'unit_cost' => Money::of((string) $item['unit_cost'])];
        }

        $products = Product::query()->whereIn('id', array_keys($lines))->get()->keyBy('id');
        foreach (array_keys($lines) as $id) {
            $product = $products->get($id);
            if ($product === null || ! $product->track_stock) {
                $message = $product === null ? 'Produk tidak ditemukan' : "{$product->name} tidak melacak stok";
                throw BusinessException::of(ErrorCode::ValidationError, $message, ['items' => [$message]]);
            }
        }

        return DB::transaction(function () use ($order, $outlet, $validated, $lines, $products): PurchaseOrder {
            if ($order === null) {
                $order = new PurchaseOrder(['outlet_id' => $outlet->id]);
                $order->forceFill(['number' => $this->numbers->next($outlet, DocumentNumberGenerator::PURCHASE)]);
            } else {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                if (! $order->isDraft()) {
                    throw BusinessException::of(ErrorCode::ValidationError, 'PO yang sudah dikirim tidak bisa diubah');
                }
            }

            $order->fill([
                'supplier_id' => $validated['supplier_id'],
                'ordered_on' => $validated['ordered_on'],
                'expected_on' => $validated['expected_on'] ?? null,
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            ]);

            $total = '0.00';
            $rows = [];
            $position = 0;
            foreach ($lines as $productId => $line) {
                $lineTotal = Money::mul($line['unit_cost'], $line['qty']);
                $total = Money::add($total, $lineTotal);
                $rows[] = [
                    'product_id' => $productId,
                    'product_name' => $products[$productId]->name,
                    'qty' => $line['qty'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $lineTotal,
                    'sort_order' => $position++,
                ];
            }

            $order->forceFill(['total_cost' => $total])->save();
            $order->items()->delete();
            foreach ($rows as $row) {
                PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, ...$row]);
            }

            return $order->load('items');
        });
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'],
            'ordered_on' => ['required', 'date_format:Y-m-d'],
            'expected_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:ordered_on'],
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
        ];
    }
}
