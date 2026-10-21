{{-- Pratinjau tampilan (Q128): sidebar mini + kartu dengan tombol, dihitung dari pilihan form (belum disimpan). --}}
@php
    $p = \App\Support\TenantAppearance::palette($hex);
    $light = $sidebar === 'light';
@endphp
<div class="gs-theme-preview" style="--pv-primary: {{ $p[600] }}; --pv-primary-lt: color-mix(in srgb, {{ $p[600] }} 10%, transparent); --pv-side: {{ $light ? '#ffffff' : $p[600] }}; --pv-side-text: {{ $light ? '#4b5563' : '#ffffff' }};">
    <div class="gs-tp-side">
        <div class="gs-tp-brand">{{ \Illuminate\Support\Str::limit($name, 14) }}</div>
        <div class="gs-tp-item is-active"></div>
        <div class="gs-tp-item"></div>
        <div class="gs-tp-item"></div>
        <div class="gs-tp-item"></div>
    </div>
    <div class="gs-tp-main">
        <div class="gs-tp-card">
            <div class="gs-tp-title"><span class="gs-tp-icon"></span>Daftar produk</div>
            <div class="gs-tp-line"></div>
            <div class="gs-tp-line is-short"></div>
            <div class="gs-tp-actions"><span class="gs-tp-btn">Simpan</span><span class="gs-tp-link">Batal</span></div>
        </div>
    </div>
</div>
