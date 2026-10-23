<?php

declare(strict_types=1);

namespace App\Livewire\Cashier;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Support\CurrentOutlet;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Badge jumlah pesanan meja yang perlu ditangani di menu kasir web (ADR 0014, Q71; ADR 0027): pesanan lunas yang masih
 * Antre + pesanan lama yang menunggu diterima. Polling 10 detik, bunyi singkat saat bertambah (tanpa WebSocket).
 */
final class SelfOrderBadge extends Component
{
    public int $count = 0;

    /** Pesanan diterima/ditolak di halaman ini: hitung ulang tanpa bunyi. */
    #[On('self-orders-changed')]
    public function refreshCount(): void
    {
        $this->count = PHP_INT_MAX;
    }

    public function render(): View
    {
        $outletId = CurrentOutlet::getOrFail()->id;
        $count = SelfOrder::query()->where('outlet_id', $outletId)->where('status', SelfOrderStatus::Pending)->count()
            + Order::query()->where('outlet_id', $outletId)->where('fulfillment_status', FulfillmentStatus::Queued)->where('status', OrderStatus::Completed)->count();

        if ($count > $this->count && $this->count !== PHP_INT_MAX) {
            $this->dispatch('self-order-new');
        }
        $this->count = $count;

        return view('livewire.cashier.self-order-badge');
    }
}
