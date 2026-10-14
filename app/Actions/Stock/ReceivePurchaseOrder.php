<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Terima barang PO (ADR 0021; izin stock.receive): membuat DRAF stok masuk berisi sisa qty & harga PO, terhubung ke
 * PO. Qty/harga bisa diubah sebelum diposting; posting menambah qty diterima PO (SyncPurchaseOrderReceipts).
 */
final class ReceivePurchaseOrder
{
    public function __construct(private readonly SaveStockReceipt $save) {}

    /** @throws BusinessException */
    public function handle(PurchaseOrder $order): StockReceipt
    {
        if (! $order->status->isOpen()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Hanya PO yang sudah dikirim dan belum diterima penuh yang bisa diterima');
        }

        $items = $order->items()->get()->filter(fn (PurchaseOrderItem $item): bool => $item->remaining() > 0)->values();
        if ($items->isEmpty()) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Semua item PO sudah diterima');
        }

        $outlet = Outlet::query()->findOrFail($order->outlet_id);

        return DB::transaction(function () use ($order, $outlet, $items): StockReceipt {
            $receipt = $this->save->handle(null, $outlet, [
                'supplier_id' => $order->supplier_id,
                'received_on' => $outlet->localDate(),
                'notes' => 'Dari '.$order->number,
                'items' => $items->map(fn (PurchaseOrderItem $item): array => [
                    'product_id' => $item->product_id,
                    'qty' => $item->remaining(),
                    'unit_cost' => $item->unit_cost,
                ])->all(),
            ]);
            $receipt->forceFill(['purchase_order_id' => $order->id])->save();

            return $receipt;
        });
    }
}
