{{-- Kasir web → Pesanan meja (ADR 0014): polling 5 detik. Gaya Q131. --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
<div class="pos-page" wire:poll.5s>
    <header class="pos-page-head">
        <div>
            <h1>Pesanan meja</h1>
            <p>Pelanggan membayar dari HP; tagihan yang belum lunas ada di Belum dibayar. Terima/tolak hanya untuk pesanan lama.</p>
        </div>
    </header>

    <div @class(['pos-board', 'is-3' => $this->paid->isNotEmpty()])>
        <section class="pos-card">
            <div class="pos-card-head">
                <h2>Pesanan masuk <span @class(['pos-count', 'is-alert' => $this->incoming->isNotEmpty()])>{{ $this->incoming->count() }}</span></h2>
            </div>

            @forelse ($this->incoming as $selfOrder)
                <article class="pos-ticket" wire:key="in-{{ $selfOrder->id }}">
                    <header class="pos-ticket-head">
                        <span class="pos-table-chip">@svg('heroicon-o-map-pin', ['aria-hidden' => 'true']) {{ $selfOrder->diningTable->name ?? '—' }}</span>
                        <strong class="pos-ticket-name">{{ $selfOrder->customer_name }}</strong>
                        @if ($selfOrder->member)
                            <span class="pos-badge is-open">@svg('heroicon-o-user', ['aria-hidden' => 'true']) {{ $selfOrder->member->name }}</span>
                        @endif
                    </header>
                    <p class="pos-ticket-meta">
                        <span class="num-tabular">{{ $selfOrder->created_at->setTimezone($timezone)->format('H:i') }}</span>
                        <span class="pos-badge">{{ $selfOrder->created_at->diffForHumans(short: true) }}</span>
                    </p>
                    <ul class="pos-ticket-items">
                        @foreach ($selfOrder->items as $item)
                            <li>
                                <span class="pos-receipt-qty">{{ $item['qty'] }}×</span>
                                <span class="pos-receipt-name">{{ $this->productNames[$item['product_id']] ?? 'Produk' }}@if (filled($item['notes'] ?? null))<small class="is-note">{{ $item['notes'] }}</small>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($selfOrder->notes || $selfOrder->voucher_code)
                        <p class="pos-ticket-note">
                            @if ($selfOrder->notes)@svg('heroicon-o-chat-bubble-left-ellipsis', ['aria-hidden' => 'true']) {{ $selfOrder->notes }}@endif
                            @if ($selfOrder->voucher_code)<span class="pos-badge">Voucher {{ $selfOrder->voucher_code }}</span>@endif
                        </p>
                    @endif
                    <footer class="pos-ticket-foot">
                        @if ($rejectingId === $selfOrder->id)
                            {{-- Tolak dua langkah: alasan wajib sebelum dikirim --}}
                            <form class="pos-reject-form" wire:submit="reject">
                                <input type="text" wire:model="rejectReason" maxlength="255" placeholder="Alasan, mis. menu habis" aria-label="Alasan menolak" required x-init="$nextTick(() => $el.focus())">
                                <button type="button" class="site-btn site-btn-outline" wire:click="cancelReject">Batal</button>
                                <button type="submit" class="site-btn pos-btn-danger">Tolak</button>
                            </form>
                        @else
                            <span class="pos-ticket-total"><small>Estimasi</small>{{ $money($selfOrder->estimated_total) }}</span>
                            <span class="pos-ticket-actions">
                                <button type="button" class="site-btn pos-btn-outline-danger" wire:click="startReject('{{ $selfOrder->id }}')">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true']) Tolak</button>
                                <button type="button" class="site-btn site-btn-primary" wire:click="accept('{{ $selfOrder->id }}')" wire:loading.attr="disabled">@svg('heroicon-o-check', ['aria-hidden' => 'true']) Terima</button>
                            </span>
                        @endif
                    </footer>
                </article>
            @empty
                <div class="pos-empty">
                    <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-qr-code')</span>
                    <strong>Belum ada pesanan masuk</strong>
                    <p>Pesanan baru dari QR meja tidak perlu diterima lagi.</p>
                </div>
            @endforelse
        </section>

        <section class="pos-card">
            <div class="pos-card-head">
                <h2>Belum dibayar <span class="pos-count">{{ $this->unpaid->count() }}</span></h2>
            </div>

            @forelse ($this->unpaid as $order)
                <article class="pos-ticket" wire:key="un-{{ $order->id }}">
                    <header class="pos-ticket-head">
                        <span class="pos-table-chip">@svg('heroicon-o-map-pin', ['aria-hidden' => 'true']) {{ $order->table_label }}</span>
                        @if ($order->notes)<strong class="pos-ticket-name">{{ $order->notes }}</strong>@endif
                    </header>
                    <p class="pos-ticket-meta">
                        <span class="pos-ticket-number">{{ $order->order_number }}</span>
                        <span class="pos-badge is-awaiting_payment">Diterima {{ $order->accepted_at?->diffForHumans(short: true) }}</span>
                    </p>
                    <footer class="pos-ticket-foot">
                        <span class="pos-ticket-total"><small>Total</small>{{ $money($order->grand_total) }}</span>
                        <span class="pos-ticket-actions">
                            <a class="site-btn site-btn-outline" href="{{ route('cashier.orders.show', $order) }}">@svg('heroicon-o-eye', ['aria-hidden' => 'true']) Detail</a>
                            <a class="site-btn site-btn-primary" href="{{ route('cashier.home', ['bill' => $order->id]) }}">@svg('heroicon-o-banknotes', ['aria-hidden' => 'true']) Bayar</a>
                        </span>
                    </footer>
                </article>
            @empty
                <div class="pos-empty">
                    <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-check-badge')</span>
                    <strong>Semua sudah dibayar</strong>
                    <p>Tidak ada pesanan meja yang menunggu bayar.</p>
                </div>
            @endforelse
        </section>

        @if ($this->paid->isNotEmpty())
            {{-- Sudah dibayar (ADR 0027): langkah pengantaran sementara juga di sini; halaman utama = Antrean --}}
            <section class="pos-card">
                <div class="pos-card-head">
                    <h2>Sudah dibayar <span class="pos-count is-alert">{{ $this->paid->count() }}</span></h2>
                    <a class="site-btn site-btn-outline" href="{{ route('cashier.queue') }}">@svg('heroicon-o-queue-list', ['aria-hidden' => 'true']) Buka antrean</a>
                </div>
                @foreach ($this->paid as $order)
                    @include('livewire.cashier.partials.fulfillment-ticket', ['order' => $order, 'customer' => $this->paidCustomers[$order->id] ?? null])
                @endforeach
            </section>
        @endif
    </div>
</div>
