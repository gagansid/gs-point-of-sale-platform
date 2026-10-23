<?php

declare(strict_types=1);

namespace App\Livewire\Cashier;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Livewire\Cashier\Concerns\AdvancesFulfillment;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kasir web → Antrean (/cashier/queue, ADR 0027 K8; izin order.fulfill): pesanan meja lunas dimajukan sampai Diantar.
 * Tiga kolom Antre/Disiapkan · Siap diantar · Diantar (1 jam terakhir), polling 5 detik, filter area meja.
 *
 * @property-read Collection<int, Order> $orders
 * @property-read array<string, string> $customers
 * @property-read list<string> $areas
 */
final class FulfillmentQueue extends Component
{
    use AdvancesFulfillment;

    #[Url(as: 'area', except: '')]
    public string $area = '';

    public function mount(): void
    {
        abort_unless(WebCashier::user()?->can('order.fulfill') ?? false, 403);
    }

    /** @return Collection<int, Order> pesanan aktif + yang diantar 1 jam terakhir, paling lama lunas di atas */
    #[Computed]
    public function orders(): Collection
    {
        $outletId = CurrentOutlet::getOrFail()->id;

        return Order::query()->with(['items.options'])
            ->where('outlet_id', $outletId)->where('channel', OrderChannel::SelfOrder)->where('status', OrderStatus::Completed)
            ->where(fn ($q) => $q->whereIn('fulfillment_status', FulfillmentStatus::open())
                ->orWhere(fn ($q) => $q->whereIn('fulfillment_status', [FulfillmentStatus::Served, FulfillmentStatus::AutoCompleted])->where('served_at', '>=', now()->subHour())))
            ->when($this->area !== '', fn ($q) => $q->whereIn('dining_table_id', DiningTable::query()->where('outlet_id', $outletId)->where('area', $this->area)->select('id')))
            ->oldest('completed_at')->limit(150)->get()->toBase();
    }

    /** @return array<string, string> order_id => nama pemesan (dari pesanan HP) */
    #[Computed]
    public function customers(): array
    {
        return SelfOrder::query()->whereIn('order_id', $this->orders->pluck('id'))->pluck('customer_name', 'order_id')->all();
    }

    /** @return list<string> area meja di outlet ini (filter) */
    #[Computed]
    public function areas(): array
    {
        return DiningTable::query()->where('outlet_id', CurrentOutlet::getOrFail()->id)->whereNotNull('area')->where('area', '!=', '')
            ->distinct()->orderBy('area')->pluck('area')->all();
    }

    public function render(): View
    {
        $orders = $this->orders;

        return view('livewire.cashier.fulfillment-queue', [
            'columns' => [
                'queued' => ['Antre & disiapkan', $orders->filter(fn (Order $o): bool => in_array($o->fulfillment_status, [FulfillmentStatus::Queued, FulfillmentStatus::Preparing], true))],
                'ready' => ['Siap diantar', $orders->where('fulfillment_status', FulfillmentStatus::Ready)],
                'served' => ['Diantar (1 jam terakhir)', $orders->filter(fn (Order $o): bool => $o->fulfillment_status?->isFinal() ?? false)->sortByDesc('served_at')],
            ],
            'timezone' => CurrentOutlet::timezone(),
        ]);
    }
}
