<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Filament\Dashboard\Resources\StockTransfers\StockTransferResource;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockReceipt;
use App\Models\StockTransfer;
use Illuminate\Support\Collection;

/**
 * Dokumen asal baris riwayat stok (`reference_id`): order, retur, stok masuk, opname, transfer.
 * Dimuat sekaligus per halaman tabel (tanpa N+1); dokumen di luar akses user tampil tanpa tautan.
 */
final class StockMovementReferences
{
    /** @var array<string, array{label: string, url: string|null}> movement id → dokumen */
    private static array $resolved = [];

    /**
     * @param  Collection<int, StockMovement>  $page  baris halaman tabel saat ini
     * @return array{label: string, url: string|null}|null
     */
    public static function for(StockMovement $movement, Collection $page): ?array
    {
        if ($movement->reference_id === null) {
            return null;
        }

        if (! array_key_exists($movement->id, self::$resolved)) {
            self::load($page->concat([$movement])->unique('id'));
        }

        return self::$resolved[$movement->id] ?? null;
    }

    /** @param  Collection<int, StockMovement>  $movements */
    private static function load(Collection $movements): void
    {
        $ids = fn (array $types): array => $movements
            ->filter(fn (StockMovement $m): bool => $m->reference_id !== null && in_array($m->type, $types, true))
            ->pluck('reference_id')->unique()->values()->all();

        $orders = Order::query()->whereIn('id', $ids([StockMovementType::Sale, StockMovementType::VoidReturn]))->pluck('order_number', 'id');
        $refunds = OrderRefund::query()->with('order:id,order_number')->whereIn('id', $ids([StockMovementType::Refund]))->get(['id', 'order_id'])->keyBy('id');
        $receipts = StockReceipt::query()->whereIn('id', $ids([StockMovementType::Purchase, StockMovementType::PurchaseVoid]))->pluck('number', 'id');
        $opnames = StockOpname::query()->whereIn('id', $ids([StockMovementType::Opname]))->pluck('number', 'id');
        $transfers = StockTransfer::query()->whereIn('id', $ids([StockMovementType::TransferOut, StockMovementType::TransferIn, StockMovementType::TransferCancel]))->pluck('number', 'id');

        foreach ($movements as $movement) {
            $ref = (string) $movement->reference_id;

            self::$resolved[$movement->id] = match ($movement->type) {
                StockMovementType::Sale, StockMovementType::VoidReturn => isset($orders[$ref])
                    ? self::link((string) $orders[$ref], OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $ref]) : null)
                    : null,
                StockMovementType::Refund => ($order = $refunds[$ref]->order ?? null) !== null
                    ? self::link('Retur '.$order->order_number, OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $order->id]) : null)
                    : null,
                StockMovementType::Purchase, StockMovementType::PurchaseVoid => isset($receipts[$ref])
                    ? self::link((string) $receipts[$ref], StockReceiptResource::canViewAny() ? StockReceiptResource::getUrl('view', ['record' => $ref]) : null)
                    : null,
                StockMovementType::Opname => isset($opnames[$ref])
                    ? self::link((string) $opnames[$ref], StockOpnameResource::canViewAny() ? StockOpnameResource::getUrl('view', ['record' => $ref]) : null)
                    : null,
                StockMovementType::TransferOut, StockMovementType::TransferIn, StockMovementType::TransferCancel => isset($transfers[$ref])
                    ? self::link((string) $transfers[$ref], StockTransferResource::canViewAny() ? StockTransferResource::getUrl('view', ['record' => $ref]) : null)
                    : null,
                // Penyesuaian manual: reference_id = idempotency key dari client, bukan dokumen
                default => null,
            };
        }
    }

    /** @return array{label: string, url: string|null} */
    private static function link(string $label, ?string $url): array
    {
        return ['label' => $label, 'url' => $url];
    }
}
