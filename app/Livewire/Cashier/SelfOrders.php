<?php

declare(strict_types=1);

namespace App\Livewire\Cashier;

use App\Actions\SelfOrder\AcceptSelfOrder;
use App\Actions\SelfOrder\RejectSelfOrder;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Kasir web → Pesanan meja (ADR 0014, Q71; izin self_order.handle): pesanan masuk (terima/tolak) dan pesanan meja
 * yang menunggu bayar (bayar di layar Transaksi). Diperbarui lewat polling (tanpa WebSocket, ADR 0004).
 *
 * @property-read Collection<int, SelfOrder> $incoming
 * @property-read Collection<int, Order> $unpaid
 */
final class SelfOrders extends Component
{
    public ?string $rejectingId = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('self_order.handle'), 403);
    }

    /** @return Collection<int, SelfOrder> pesanan menunggu kasir di outlet ini, paling lama di atas */
    #[Computed]
    public function incoming(): Collection
    {
        return SelfOrder::query()->with(['diningTable:id,name', 'member:id,name'])
            ->where('outlet_id', CurrentOutlet::getOrFail()->id)->where('status', SelfOrderStatus::Pending)
            ->oldest()->limit(50)->get()->toBase();
    }

    /** @return Collection<int, Order> pesanan meja diterima, belum lunas */
    #[Computed]
    public function unpaid(): Collection
    {
        return Order::query()->where('outlet_id', CurrentOutlet::getOrFail()->id)->where('channel', OrderChannel::SelfOrder)
            ->where('status', OrderStatus::AwaitingPayment)->oldest('accepted_at')->limit(50)->get()->toBase();
    }

    /** @return array<string, string> product_id => nama, untuk menampilkan isi pesanan masuk */
    #[Computed]
    public function productNames(): array
    {
        $ids = $this->incoming->flatMap(fn (SelfOrder $o): array => array_column($o->items, 'product_id'))->unique()->values()->all();

        return Product::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public function accept(string $id): void
    {
        $selfOrder = SelfOrder::query()->find($id);

        if ($selfOrder === null) {
            return;
        }

        try {
            $order = app(AcceptSelfOrder::class)->handle($this->user(), WebCashier::device(), $selfOrder);
        } catch (BusinessException $e) {
            $this->toast($e->getMessage(), 'danger');
            unset($this->incoming);

            return;
        }

        $this->toast($order->status === OrderStatus::Completed
            ? "Pesanan {$order->order_number} diterima (lunas dengan voucher)"
            : "Pesanan {$order->order_number} diterima, menunggu bayar");
        unset($this->incoming, $this->unpaid);
    }

    public function startReject(string $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->reset('rejectingId', 'rejectReason');
    }

    public function reject(): void
    {
        $selfOrder = $this->rejectingId !== null ? SelfOrder::query()->find($this->rejectingId) : null;

        if ($selfOrder === null) {
            $this->cancelReject();

            return;
        }

        try {
            app(RejectSelfOrder::class)->handle($this->user(), CurrentOutlet::getOrFail()->id, $selfOrder, $this->rejectReason);
        } catch (BusinessException $e) {
            $this->toast($e->getMessage(), 'danger');

            return;
        }

        $this->toast('Pesanan ditolak');
        $this->cancelReject();
        unset($this->incoming);
    }

    public function render(): View
    {
        return view('livewire.cashier.self-orders', ['timezone' => CurrentOutlet::timezone()]);
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('cashier-toast', message: $message, tone: $tone);
        // Badge menu langsung ikut berubah (tanpa menunggu polling)
        $this->dispatch('self-orders-changed');
    }

    private function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
