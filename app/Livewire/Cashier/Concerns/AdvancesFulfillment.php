<?php

declare(strict_types=1);

namespace App\Livewire\Cashier\Concerns;

use App\Actions\Fulfillment\AdvanceOrderFulfillment;
use App\Actions\Fulfillment\UndoOrderFulfillment;
use App\Enums\FulfillmentStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Livewire\Attributes\On;

/**
 * Tombol langkah pengantaran di kasir web (ADR 0027 K8): satu ketuk ke tahap berikutnya, toast Urungkan 5 detik.
 * Dipakai /cashier/queue dan menu Pesanan meja; Action yang sama dengan dashboard & API.
 */
trait AdvancesFulfillment
{
    public function advance(string $orderId): void
    {
        $order = $this->outletOrder($orderId);
        $next = $order?->fulfillment_status?->next();

        if ($order === null || $next === null) {
            return;
        }

        try {
            app(AdvanceOrderFulfillment::class)->handle($this->fulfillmentUser(), $order, $next);
        } catch (BusinessException $e) {
            $this->dispatch('cashier-toast', message: $e->getMessage(), tone: 'danger');

            return;
        }

        $label = $order->table_label ?: $order->order_number;
        $this->dispatch('cashier-toast', message: $label.' · '.$next->getLabel(), tone: 'success', undo: $order->id);
        $this->dispatch('self-orders-changed');
    }

    #[On('fulfillment-undo')]
    public function undo(string $orderId): void
    {
        $order = $this->outletOrder($orderId);
        if ($order === null) {
            return;
        }

        try {
            app(UndoOrderFulfillment::class)->handle($this->fulfillmentUser(), $order);
        } catch (BusinessException $e) {
            $this->dispatch('cashier-toast', message: $e->getMessage(), tone: 'warning');

            return;
        }

        $this->dispatch('cashier-toast', message: 'Langkah diurungkan', tone: 'info');
        $this->dispatch('self-orders-changed');
    }

    /** Umur pesanan sejak lunas: nada badge (kuning > 15 menit, merah > 30 menit). */
    public static function ageTone(Order $order): string
    {
        $minutes = $order->completed_at?->diffInMinutes(now()) ?? 0;

        return match (true) {
            $order->fulfillment_status?->isFinal() ?? false => '',
            $minutes > 30 => 'is-danger',
            $minutes > 15 => 'is-warning',
            default => '',
        };
    }

    private function outletOrder(string $orderId): ?Order
    {
        return Order::query()->where('outlet_id', CurrentOutlet::getOrFail()->id)->whereNotNull('fulfillment_status')->find($orderId);
    }

    private function fulfillmentUser(): User
    {
        return WebCashier::user() ?? abort(401);
    }

    /** Label tombol langkah berikutnya untuk tahap saat ini. */
    public static function nextLabel(?FulfillmentStatus $status): ?string
    {
        return $status?->next()?->actionLabel();
    }
}
