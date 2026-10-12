<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts\Pages\Concerns;

use App\Actions\Stock\SaveStockReceipt;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\StockReceipt;
use App\Support\CurrentOutlet;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/** Simpan draf stok masuk dari form (create & edit): error bisnis tampil sebagai notifikasi, form tetap terbuka. */
trait SavesStockReceipt
{
    /** @param array<string, mixed> $data */
    protected function saveReceipt(?StockReceipt $receipt, array $data): StockReceipt
    {
        $outletId = $receipt->outlet_id ?? (filled($data['outlet_id'] ?? null) && in_array($data['outlet_id'], CurrentOutlet::ids(), true) ? (string) $data['outlet_id'] : CurrentOutlet::getOrFail()->id);

        try {
            return app(SaveStockReceipt::class)->handle($receipt, Outlet::query()->findOrFail($outletId), [
                'id' => $receipt === null ? (string) Str::uuid7() : null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'received_on' => substr((string) ($data['received_on'] ?? ''), 0, 10),
                'invoice_number' => $data['invoice_number'] ?? null,
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
