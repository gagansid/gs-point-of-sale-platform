{{-- Tab Profil → Riwayat pesanan member di bisnis ini (semua outlet), terbaru dulu (maks. 20). --}}
<section class="so-account">
    <button type="button" class="so-back" wire:click="show('account')">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Profil</button>

    <article class="so-card is-tight">
        <h1 class="so-card-title">Riwayat pesanan</h1>
        @if ($this->memberOrders->isEmpty())
            <div class="so-empty">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-receipt-percent')</span>
                <p class="so-empty-title">Belum ada pesanan</p>
                <p>Pesanan sebagai member, dari meja maupun kasir, akan tampil di sini.</p>
            </div>
        @else
            <ul class="so-list">
                @foreach ($this->memberOrders as $order)
                    @php($tone = match ($order->status) { \App\Enums\OrderStatus::Completed => 'success', \App\Enums\OrderStatus::Voided => 'danger', default => 'warning' })
                    <li wire:key="h-{{ $order->id }}">
                        <div class="so-list-main">
                            <strong class="so-mono">{{ $order->order_number }}</strong>
                            <span class="so-muted">{{ $order->outlet?->name }} · {{ $order->created_at->setTimezone($context->outlet->timezone)->locale('id')->translatedFormat('j M Y, H.i') }}</span>
                        </div>
                        <div class="so-history-end">
                            <span class="so-list-end">{{ \App\Support\Money::format($order->grand_total) }}</span>
                            <span class="so-badge is-{{ $tone }}">{{ $order->status->getLabel() }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </article>
</section>
