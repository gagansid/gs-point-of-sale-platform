<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders\Pages\Concerns;

use App\Actions\Stock\SavePurchaseOrder;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Support\CurrentOutlet;
use Filament\Notifications\Notification;

/** Simpan draf PO dari form (create & edit): error bisnis tampil sebagai notifikasi, form tetap terbuka. */
trait SavesPurchaseOrder
{
    /** @param array<string, mixed> $data */
    protected function savePurchaseOrder(?PurchaseOrder $order, array $data): PurchaseOrder
    {
        $outletId = $order->outlet_id ?? (filled($data['outlet_id'] ?? null) && in_array($data['outlet_id'], CurrentOutlet::ids(), true) ? (string) $data['outlet_id'] : CurrentOutlet::getOrFail()->id);

        try {
            return app(SavePurchaseOrder::class)->handle($order, Outlet::query()->findOrFail($outletId), [
                'supplier_id' => (string) ($data['supplier_id'] ?? ''),
                'ordered_on' => substr((string) ($data['ordered_on'] ?? ''), 0, 10),
                'expected_on' => filled($data['expected_on'] ?? null) ? substr((string) $data['expected_on'], 0, 10) : null,
                'notes' => $data['notes'] ?? null,
                'items' => array_values(array_map(fn (array $i): array => [
                    'product_id' => (string) ($i['product_id'] ?? ''),
                    'qty' => (int) ($i['qty'] ?? 0),
                    'unit_cost' => (string) ($i['unit_cost'] ?? '0'),
                ], (array) ($data['items'] ?? []))),
            ]);
        } catch (BusinessException $e) {
            $details = is_array($e->details) ? implode(' ', array_merge(...array_values(array_map(fn (mixed $m): array => array_map('strval', (array) $m), $e->details)))) : null;
            Notification::make()->danger()->title($e->getMessage())->body($details !== '' ? $details : null)->send();
            $this->halt();

            throw $e;
        }
    }
}
