<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Pages;

use App\Filament\Dashboard\Resources\Orders\Actions\VoidOrderAction;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Order;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewOrder extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = OrderResource::class;

    /** Muat relasi sekaligus (hindari N+1; strict mode menolak lazy loading di non-production). */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['items.options', 'payments.method', 'user']);
    }

    /** Judul = nomor order (layout.md: judul halaman detail = nama objek). */
    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Order ? "Transaksi {$record->order_number}" : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [VoidOrderAction::make()];
    }
}
