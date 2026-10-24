{{--
    Layar menunggu pembayaran (ADR 0027 §5.6–5.7): QRIS di halaman (QR dirender server dari qr_string, kartu putih,
    hitung mundur, Simpan QR) atau "Tunjukkan ke kasir". Tanpa polling: status dicek saat pelanggan kembali dari aplikasi
    e-wallet (tab aktif lagi) atau menekan "Cek status pembayaran".
--}}
@php
    $payOrder = $this->payOrder();
    $remaining = $payOrder ? \App\Support\Money::sub($payOrder->grand_total, $payOrder->paid_total) : '0.00';
    $methods = $this->payMethods();
@endphp
<section class="so-pay" aria-live="polite" x-data x-on:visibilitychange.document="document.visibilityState === 'visible' && $wire.checkPayment()">
    @if ($payOrder === null)
        <div class="so-card">
            <div class="so-empty">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-clipboard-document-list')</span>
                <p class="so-empty-title">Pesanan tidak ditemukan</p>
                <div class="so-actions"><button type="button" class="so-btn is-primary" wire:click="show('status')">Lihat pesananku</button></div>
            </div>
        </div>
    @elseif ($payMethod === 'qris')
        @php($qris = $this->qrisTransaction())
        <article class="so-card so-pay-card">
            <header class="so-pay-head">
                <span class="so-muted">Bayar ke</span>
                <strong>{{ $context->tenant->name }}</strong>
                <span class="so-pay-amount so-num">{{ \App\Support\Money::format($remaining) }}</span>
                <span class="so-muted">Pesanan <span class="so-mono">{{ $payOrder->order_number }}</span> · {{ $context->table->name }}</span>
            </header>

            @if ($qris?->isQrActive())
                <div class="so-qris" wire:key="qris-{{ $qris->id }}"
                    x-data="{
                        end: Date.parse(@js($qris->expires_at->toIso8601String())),
                        left: '',
                        timer: null,
                        tick() {
                            const s = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
                            this.left = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
                            if (s === 0) { clearInterval(this.timer); this.$wire.$refresh(); }
                        },
                        destroy() { clearInterval(this.timer) },
                        save() {
                            const xml = new XMLSerializer().serializeToString(this.$refs.qr.querySelector('svg'));
                            const img = new Image();
                            img.onload = () => {
                                const canvas = document.createElement('canvas');
                                canvas.width = canvas.height = 720;
                                const ctx = canvas.getContext('2d');
                                ctx.fillStyle = 'white';
                                ctx.fillRect(0, 0, 720, 720);
                                ctx.drawImage(img, 0, 0, 720, 720);
                                const link = document.createElement('a');
                                link.download = @js('QRIS-'.$payOrder->order_number.'.png');
                                link.href = canvas.toDataURL('image/png');
                                link.click();
                            };
                            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
                        },
                    }"
                    x-init="tick(); timer = setInterval(() => tick(), 1000)">
                    <div class="so-qr-box is-lg" x-ref="qr" role="img" aria-label="Kode QRIS pesanan {{ $payOrder->order_number }}">{!! \App\Support\QrSvg::make($qris->qr_string) !!}</div>
                    <p class="so-pay-timer">@svg('heroicon-o-clock', ['aria-hidden' => 'true']) Berlaku <strong class="so-num" x-text="left">--:--</strong></p>
                    <ol class="so-steps">
                        <li>Buka aplikasi e-wallet atau m-banking.</li>
                        <li>Pilih bayar/scan QRIS, lalu pindai kode ini.</li>
                        <li>Bayar {{ \App\Support\Money::format($remaining) }}, lalu kembali ke halaman ini.</li>
                    </ol>
                    <div class="so-actions is-stack">
                        <button type="button" class="so-btn is-outline is-block" x-on:click="save()">@svg('heroicon-o-arrow-down-tray', ['aria-hidden' => 'true']) Simpan QR</button>
                    </div>
                    <p class="so-pay-wait"><span class="so-pulse" aria-hidden="true"></span> Menunggu pembayaran…</p>
                </div>
            @else
                <div class="so-empty">
                    <span class="so-empty-icon is-warning" aria-hidden="true">@svg('heroicon-o-clock')</span>
                    <p class="so-empty-title">QR sudah kedaluwarsa</p>
                    <p>Buat QR baru untuk membayar, atau bayar langsung di kasir.</p>
                    <div class="so-actions">
                        <button type="button" class="so-btn is-primary" wire:click="renewQris" wire:loading.attr="disabled" wire:target="renewQris">@svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) Buat QR baru</button>
                        @if (in_array('cashier', $methods, true))
                            <button type="button" class="so-btn is-outline" wire:click="payAtCashier">Bayar di kasir</button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($payError)
                <div class="so-alert is-danger" role="alert">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true']) <span>{{ $payError }}</span></div>
            @endif
        </article>
    @else
        {{-- Tunjukkan ke kasir: satu-satunya angka besar di halaman --}}
        <article class="so-card so-pay-card">
            <header class="so-pay-head">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-banknotes')</span>
                <strong>Tunjukkan ke kasir</strong>
                <span class="so-muted">Sebutkan nomor ini saat membayar</span>
            </header>
            <p class="so-order-number">{{ $payOrder->order_number }}</p>
            <dl class="so-totals is-compact so-pay-facts">
                <dt>Meja</dt><dd>{{ $context->table->name }}</dd>
                <dt>Atas nama</dt><dd>{{ $this->payingSelfOrder()?->customer_name }}</dd>
                <dt>Bayar sebelum</dt><dd class="so-num">{{ $this->payDeadline($payOrder)->format('H.i') }}</dd>
                <dt class="is-grand">Total</dt><dd class="is-grand">{{ \App\Support\Money::format($remaining) }}</dd>
            </dl>
            <p class="so-pay-wait"><span class="so-pulse" aria-hidden="true"></span> Menunggu pembayaran di kasir…</p>
        </article>
    @endif

    @if ($payOrder !== null)
        <div class="so-actions is-stack">
            <button type="button" class="so-btn is-primary is-block" wire:click="checkPayment" wire:loading.attr="disabled" wire:target="checkPayment">
                @svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) Cek status pembayaran
            </button>
            <button type="button" class="so-btn is-ghost is-block" wire:click="openPay('{{ $payOrderId }}')">@svg('heroicon-o-arrows-right-left', ['aria-hidden' => 'true']) Ganti metode bayar</button>
        </div>
    @endif
</section>
