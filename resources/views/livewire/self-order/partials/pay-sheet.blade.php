{{--
    Pilih pembayaran (bottom sheet, ADR 0027 §5): hanya metode yang tersedia. Total dihitung ulang server saat dibuka;
    bila berbeda dari pratinjau, perbedaannya ditampilkan. Memilih metode = keranjang menjadi tagihan (sekali).
--}}
@php
    $payOrder = $this->payOrder();
    $amount = $payOrder !== null
        ? \App\Support\Money::sub($payOrder->grand_total, $payOrder->paid_total)
        : ($this->quote['totals']?->grandTotal ?? '0.00');
    $points = $this->pointsAvailable();
    $meta = [
        'qris' => ['heroicon-o-qr-code', 'QRIS', 'Scan dari e-wallet atau m-banking apa pun'],
        'ewallet' => ['heroicon-o-device-phone-mobile', 'E-wallet / virtual account', 'GoPay, ShopeePay, atau transfer VA'],
        'cashier' => ['heroicon-o-banknotes', 'Bayar di kasir', 'Tunjukkan nomor pesanan ke kasir'],
        'points' => ['heroicon-o-star', 'Poin member', 'Saldo '.number_format($points, 0, ',', '.').' poin; sisa tagihan bayar dengan metode lain'],
    ];
@endphp
<div class="so-sheet" role="dialog" aria-modal="true" aria-labelledby="pay-title" wire:key="pay-sheet-{{ $payOrderId ?? 'cart' }}"
    x-data="{ startY: null, dy: 0 }" x-trap.noscroll="true" x-on:keydown.escape="$wire.closePay()">
    <button type="button" class="so-sheet-backdrop" wire:click="closePay" aria-label="Tutup" tabindex="-1"></button>
    <div class="so-sheet-panel" :style="dy > 0 && `transform: translateY(${dy}px); transition: none`">
        <div class="so-sheet-drag"
            x-on:touchstart.passive="startY = $event.touches[0].clientY"
            x-on:touchmove.passive="if (startY !== null) dy = Math.max(0, $event.touches[0].clientY - startY)"
            x-on:touchend="if (dy > 90) { $wire.closePay() } else { dy = 0 } startY = null">
            <span class="so-sheet-grip" aria-hidden="true"></span>
            <div class="so-sheet-head">
                <div>
                    <h2 id="pay-title" class="so-card-title">Pilih pembayaran</h2>
                    <p class="so-muted">{{ $payOrder ? 'Pesanan '.$payOrder->order_number : $context->table->name }}</p>
                </div>
                <button type="button" class="so-icon-btn is-plain" wire:click="closePay" aria-label="Tutup">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
            </div>
        </div>

        <div class="so-sheet-body">
            <div class="so-pay-total">
                <span class="so-secondary">Total dibayar</span>
                <strong class="so-num">{{ \App\Support\Money::format($amount) }}</strong>
            </div>

            @if ($totalChangedFrom)
                <div class="so-alert is-warning" role="status">@svg('heroicon-o-exclamation-triangle', ['aria-hidden' => 'true'])
                    <span>Total berubah dari {{ \App\Support\Money::format($totalChangedFrom) }} karena harga atau ketersediaan menu diperbarui.</span>
                </div>
            @endif

            @if ($payError)
                <div class="so-alert is-danger" role="alert">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true']) <span>{{ $payError }}</span></div>
            @endif

            <ul class="so-pay-methods" role="list">
                @foreach ($this->payMethods() as $method)
                    @php([$icon, $label, $hint] = $meta[$method])
                    <li wire:key="pm-{{ $method }}">
                        <button type="button" class="so-pay-method" wire:click="choosePay('{{ $method }}')" wire:loading.attr="disabled" wire:target="choosePay">
                            <span class="so-pay-method-icon" aria-hidden="true">@svg($icon)</span>
                            <span class="so-pay-method-text">
                                <strong>{{ $label }}</strong>
                                <span class="so-muted">{{ $hint }}</span>
                            </span>
                            <span class="so-pay-method-go" aria-hidden="true" wire:loading.remove wire:target="choosePay('{{ $method }}')">@svg('heroicon-o-chevron-right')</span>
                            <span class="so-spinner" aria-hidden="true" wire:loading wire:target="choosePay('{{ $method }}')"></span>
                        </button>
                    </li>
                @endforeach
            </ul>

            @if (array_intersect(['qris', 'ewallet'], $this->payMethods()) !== [])
                <p class="so-muted so-foot">@svg('heroicon-o-lock-closed', ['class' => 'so-inline-icon', 'aria-hidden' => 'true']) Pembayaran online diproses Midtrans. Setelah membayar, kembali ke halaman ini untuk melihat status.</p>
            @endif
        </div>
    </div>
</div>
