<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockDocumentStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockReceiptItem;

/**
 * Hitung ulang qty diterima & status PO dari semua stok masuk PO yang diposting (ADR 0021). Dipanggil di dalam
 * transaksi Posting/Batal stok masuk; PO yang dibatalkan tetap berstatus dibatalkan.
 */
final class SyncPurchaseOrderReceipts
{
    public function handle(string $purchaseOrderId): void
    {
        $order = PurchaseOrder::query()->lockForUpdate()->find($purchaseOrderId);

        if ($order === null || $order->isDraft()) {
            return;
        }

        $received = StockReceiptItem::query()
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $order->id)->where('status', StockDocumentStatus::Posted))
            ->selectRaw('product_id, SUM(qty) as qty')->groupBy('product_id')->pluck('qty', 'product_id');

        $items = $order->items()->get();
        foreach ($items as $item) {
            $item->forceFill(['received_qty' => (int) ($received[$item->product_id] ?? 0)])->save();
        }

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $anyReceived = $items->contains(fn (PurchaseOrderItem $item): bool => $item->received_qty > 0);
        $allReceived = $items->every(fn (PurchaseOrderItem $item): bool => $item->remaining() === 0);

        $order->forceFill(['status' => match (true) {
            $allReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::Partial,
            default => PurchaseOrderStatus::Ordered,
        }])->save();
    }
}
