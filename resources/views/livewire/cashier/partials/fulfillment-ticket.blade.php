{{--
    Tiket pesanan meja lunas (ADR 0027 K8): chip meja, pemesan + member, nomor, umur sejak lunas (kuning > 15 menit,
    merah > 30 menit), item + opsi + catatan, satu tombol langkah berikutnya, ⋯ Detail/Batalkan (void dengan PIN).
--}}
@php
    $status = $order->fulfillment_status;
    $next = $status?->next();
    $minutes = (int) $order->completed_at?->diffInMinutes(now());
@endphp
<article class="pos-ticket" wire:key="ff-{{ $order->id }}">
    <header class="pos-ticket-head">
        <span class="pos-table-chip">@svg('heroicon-o-map-pin', ['aria-hidden' => 'true']) {{ $order->table_label ?: '—' }}</span>
        <strong class="pos-ticket-name">{{ $customer ?? $order->member_name ?? 'Pelanggan' }}</strong>
        @if ($order->member_name)
            <span class="pos-badge is-open">@svg('heroicon-o-user', ['aria-hidden' => 'true']) Member</span>
        @endif
    </header>
    <p class="pos-ticket-meta">
        <span class="pos-ticket-number">{{ $order->order_number }}</span>
        @if ($status?->isFinal())
            <span @class(['pos-badge', 'is-completed' => $status === \App\Enums\FulfillmentStatus::Served])>{{ $status->getLabel() }} {{ $order->served_at?->setTimezone($timezone)->format('H.i') }}</span>
        @else
            <span class="pos-badge pos-age {{ \App\Livewire\Cashier\FulfillmentQueue::ageTone($order) }}" title="Sejak lunas {{ $order->completed_at?->setTimezone($timezone)->format('H.i') }}">@svg('heroicon-o-clock', ['aria-hidden' => 'true']) {{ $minutes }} mnt</span>
            <span @class(['pos-badge', 'is-open' => $status === \App\Enums\FulfillmentStatus::Preparing])>{{ $status?->getLabel() }}</span>
        @endif
    </p>
    <ul class="pos-ticket-items">
        @foreach ($order->items as $item)
            <li>
                <span class="pos-receipt-qty">{{ $item->qty }}×</span>
                <span class="pos-receipt-name">{{ $item->product_name }}@if ($item->options->isNotEmpty())<small>{{ $item->options->pluck('option_name')->implode(', ') }}</small>@endif @if (filled($item->notes))<small class="is-note">{{ $item->notes }}</small>@endif</span>
            </li>
        @endforeach
    </ul>
    @if (filled($order->notes) && str_contains((string) $order->notes, ' · '))
        <p class="pos-ticket-note">@svg('heroicon-o-chat-bubble-left-ellipsis', ['aria-hidden' => 'true']) {{ \Illuminate\Support\Str::after((string) $order->notes, ' · ') }}</p>
    @endif
    <footer class="pos-ticket-foot">
        <a class="site-btn site-btn-outline pos-btn-icon" href="{{ route('cashier.orders.show', $order) }}" title="Detail, batalkan, atau refund" aria-label="Detail pesanan {{ $order->order_number }}">@svg('heroicon-o-ellipsis-horizontal', ['aria-hidden' => 'true'])</a>
        @if ($next)
            <button type="button" class="site-btn site-btn-primary pos-step-btn" wire:click="advance('{{ $order->id }}')" wire:loading.attr="disabled" wire:target="advance('{{ $order->id }}')">
                @svg($next->getIcon(), ['aria-hidden' => 'true']) {{ $next->actionLabel() }}
            </button>
        @endif
    </footer>
</article>
