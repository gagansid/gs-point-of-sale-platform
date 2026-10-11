{{-- Kasir web → Pesanan meja (ADR 0014): polling 5 detik. --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
<div class="cashier-grid-2" wire:poll.5s>
    <section class="cashier-card">
        <h1>Pesanan masuk <span class="cashier-count">{{ $this->incoming->count() }}</span></h1>
        <p class="cashier-muted">Periksa ketersediaan lalu terima; pelanggan membayar setelah diterima.</p>

        @forelse ($this->incoming as $selfOrder)
            <article class="cashier-self-order" wire:key="in-{{ $selfOrder->id }}">
                <header>
                    <strong>{{ $selfOrder->diningTable->name ?? '—' }}</strong>
                    <span>{{ $selfOrder->customer_name }}{{ $selfOrder->member ? ' · member '.$selfOrder->member->name : '' }}</span>
                    <span class="cashier-muted">{{ $selfOrder->created_at->setTimezone($timezone)->format('H.i') }} · {{ $selfOrder->created_at->diffForHumans(short: true) }}</span>
                </header>
                <ul>
                    @foreach ($selfOrder->items as $item)
                        <li>{{ $item['qty'] }}× {{ $this->productNames[$item['product_id']] ?? 'Produk' }}{{ filled($item['notes'] ?? null) ? ' — '.$item['notes'] : '' }}</li>
                    @endforeach
                </ul>
                @if ($selfOrder->notes || $selfOrder->voucher_code)
                    <p class="cashier-muted">{{ $selfOrder->notes }}{{ $selfOrder->voucher_code ? ' · voucher '.$selfOrder->voucher_code : '' }}</p>
                @endif
                <footer>
                    <span>± {{ $money($selfOrder->estimated_total) }}</span>
                    @if ($rejectingId === $selfOrder->id)
                        <form class="cashier-inline-form" wire:submit="reject">
                            <input type="text" wire:model="rejectReason" maxlength="255" placeholder="Alasan, mis. menu habis" aria-label="Alasan menolak" required x-init="$nextTick(() => $el.focus())">
                            <button type="button" class="site-btn site-btn-outline" wire:click="cancelReject">Batal</button>
                            <button type="submit" class="site-btn pos-btn-danger">Tolak</button>
                        </form>
                    @else
                        <button type="button" class="site-btn site-btn-outline" wire:click="startReject('{{ $selfOrder->id }}')">Tolak</button>
                        <button type="button" class="site-btn site-btn-primary" wire:click="accept('{{ $selfOrder->id }}')" wire:loading.attr="disabled">Terima</button>
                    @endif
                </footer>
            </article>
        @empty
            <p class="cashier-muted">Belum ada pesanan masuk.</p>
        @endforelse
    </section>

    <section class="cashier-card">
        <h2>Belum dibayar <span class="cashier-count">{{ $this->unpaid->count() }}</span></h2>
        @forelse ($this->unpaid as $order)
            <article class="cashier-self-order" wire:key="un-{{ $order->id }}">
                <header>
                    <strong>{{ $order->table_label }}</strong>
                    <span>{{ $order->order_number }} · {{ $order->notes }}</span>
                    <span class="cashier-muted">diterima {{ $order->accepted_at?->diffForHumans(short: true) }}</span>
                </header>
                <footer>
                    <span>{{ $money($order->grand_total) }}</span>
                    <a class="site-btn site-btn-outline" href="{{ route('cashier.orders.show', $order) }}">Detail</a>
                    <a class="site-btn site-btn-primary" href="{{ route('cashier.home', ['bill' => $order->id]) }}">Bayar</a>
                </footer>
            </article>
        @empty
            <p class="cashier-muted">Tidak ada pesanan meja yang menunggu bayar.</p>
        @endforelse
    </section>
</div>
