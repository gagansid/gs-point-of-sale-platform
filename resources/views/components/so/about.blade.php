{{-- Dialog "Tentang aplikasi" (ADR 0027): versi gs.POS dipindah dari kaki halaman ke sini. --}}
@props(['tenant'])
<dialog class="so-dialog" id="so-about" aria-labelledby="so-about-title">
    <div class="so-dialog-body">
        <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-device-phone-mobile')</span>
        <h2 class="so-card-title" id="so-about-title">Tentang aplikasi</h2>
        <p class="so-secondary">Halaman pesan & member {{ $tenant->name }} dijalankan dengan gs.POS.</p>
        <dl class="so-totals">
            <dt>Aplikasi</dt><dd>gs.POS</dd>
            <dt>Versi</dt><dd class="so-num">{{ config('app.version') }}</dd>
            <dt>Bisnis</dt><dd>{{ $tenant->name }}</dd>
        </dl>
        <p class="so-muted">Data pesanan & member dikelola oleh {{ $tenant->name }}. Pembayaran online diproses Midtrans.</p>
    </div>
    <form method="dialog" class="so-dialog-foot">
        <button type="submit" class="so-btn is-outline is-block">Tutup</button>
    </form>
</dialog>
