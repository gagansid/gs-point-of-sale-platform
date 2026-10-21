{{-- Badge pesanan meja di menu kasir (ADR 0014); bunyi pendek saat ada pesanan baru (Web Audio, tanpa file). --}}
{{-- Logika di method x-data: Alpine membungkus ekspresi x-on dengan "return", sehingga "try { … }" langsung di atribut = SyntaxError --}}
<span wire:poll.10s x-data="{
    beep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            osc.frequency.value = 880;
            osc.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.18);
        } catch (e) {}
    },
}" x-on:self-order-new.window="beep()">@if ($count > 0)<span class="cashier-nav-badge" aria-label="{{ $count }} pesanan meja baru">{{ $count }}</span>@endif</span>
