<?php

declare(strict_types=1);

namespace App\Livewire\Cashier;

use App\Enums\SelfOrderStatus;
use App\Models\SelfOrder;
use App\Support\CurrentOutlet;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Badge jumlah pesanan meja yang menunggu kasir di menu kasir web (ADR 0014, Q71): polling 10 detik, bunyi singkat
 * saat ada pesanan baru (tanpa WebSocket).
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
        $count = SelfOrder::query()->where('outlet_id', CurrentOutlet::getOrFail()->id)->where('status', SelfOrderStatus::Pending)->count();

        if ($count > $this->count && $this->count !== PHP_INT_MAX) {
            $this->dispatch('self-order-new');
        }
        $this->count = $count;

        return view('livewire.cashier.self-order-badge');
    }
}
