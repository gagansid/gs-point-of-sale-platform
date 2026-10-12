<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\Supplier;
use App\Services\Stock\DocumentNumberGenerator;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Buat/ubah DRAF stok masuk (ADR 0015, Q79; izin stock.receive). Item menggantikan item lama; hanya produk lacak stok
 * milik bisnis; supplier harus aktif. Stok & modal belum berubah sampai diposting. `id` baru dari klien = idempotency key.
 */
final class SaveStockReceipt
{
    public const MAX_ITEMS = 200;

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data  id (baru), supplier_id, received_on (Y-m-d), invoice_number, notes, items[{product_id, qty, unit_cost}]
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?StockReceipt $receipt, Outlet $outlet, array $data): StockReceipt
    {
        if ($receipt === null && filled($data['id'] ?? null) && ($existing = Idempotency::existing(StockReceipt::class, (string) $data['id'])) !== null) {
            return $existing;
        }

        if ($receipt !== null && ! $receipt->isDraft()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Stok masuk yang sudah diposting/dibatalkan tidak bisa diubah');
        }

        $validated = Validator::make($data, self::rules(), [], [
            'received_on' => 'tanggal terima', 'invoice_number' => 'nomor nota', 'notes' => 'catatan', 'items' => 'item',
            'items.*.product_id' => 'produk', 'items.*.qty' => 'qty', 'items.*.unit_cost' => 'harga beli',
        ])->validate();

        if ($validated['received_on'] > $outlet->localDate()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tanggal terima tidak boleh di masa depan', ['received_on' => ['Tanggal terima tidak boleh di masa depan']]);
        }

        $supplierId = $validated['supplier_id'] ?? null;
        if ($supplierId !== null && ! Supplier::query()->whereKey($supplierId)->where('is_active', true)->exists()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Supplier tidak ditemukan atau nonaktif', ['supplier_id' => ['Supplier tidak ditemukan atau nonaktif']]);
        }

        $products = Product::query()->whereIn('id', array_column($validated['items'], 'product_id'))->get()->keyBy('id');
        $errors = [];
        foreach ($validated['items'] as $i => $item) {
            $product = $products->get($item['product_id']);
            if ($product === null) {
                $errors["items.{$i}.product_id"] = ['Produk tidak ditemukan'];
            } elseif (! $product->track_stock) {
                $errors["items.{$i}.product_id"] = ["{$product->name} tidak melacak stok"];
            }
        }
        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Ada item yang belum benar', $errors);
        }

        return DB::transaction(function () use ($receipt, $outlet, $validated, $products, $supplierId): StockReceipt {
            if ($receipt === null) {
                $receipt = new StockReceipt(['outlet_id' => $outlet->id]);
                if (filled($validated['id'] ?? null)) {
                    $receipt->id = (string) $validated['id'];
                }
                $receipt->forceFill(['number' => $this->numbers->next($outlet, DocumentNumberGenerator::RECEIPT)]);
            } else {
                $receipt = StockReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                if (! $receipt->isDraft()) {
                    throw BusinessException::of(ErrorCode::ValidationError, 'Stok masuk yang sudah diposting/dibatalkan tidak bisa diubah');
                }
            }

            $receipt->fill([
                'supplier_id' => $supplierId,
                'received_on' => $validated['received_on'],
                'invoice_number' => filled($validated['invoice_number'] ?? null) ? trim((string) $validated['invoice_number']) : null,
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            ]);

            $total = '0.00';
            $rows = [];
            foreach (array_values($validated['items']) as $position => $item) {
                $unitCost = Money::of((string) $item['unit_cost']);
                $lineTotal = Money::mul($unitCost, (int) $item['qty']);
                $total = Money::add($total, $lineTotal);
                $rows[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $products[$item['product_id']]->name,
                    'qty' => (int) $item['qty'],
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'sort_order' => $position,
                ];
            }

            $receipt->forceFill(['total_cost' => $total])->save();
            $receipt->items()->delete();
            foreach ($rows as $row) {
                StockReceiptItem::query()->create(['stock_receipt_id' => $receipt->id, ...$row]);
            }

            return $receipt->load('items');
        });
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'invoice_number' => ['nullable', 'string', 'max:50', 'not_regex:/^[=+\-@]/'],
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
        ];
    }
}
