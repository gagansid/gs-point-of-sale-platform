{{-- Sistem → Maintenance: status layanan, URL bypass, dampak & langkah aman. --}}
@php($bypass = $this->bypassUrl())
<x-filament-panels::page>
    <div class="gs-overview">
        <div class="gs-kpi-grid gs-kpi-grid--3">
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-signal" /></span><span class="gs-kpi-label">Status layanan</span></div>
                <div class="gs-kpi-value">
                    @if ($this->isEnabled())
                        <x-filament::badge color="danger" icon="heroicon-o-pause-circle">Maintenance aktif</x-filament::badge>
                    @else
                        <x-filament::badge color="success" icon="heroicon-o-play-circle">Normal</x-filament::badge>
                    @endif
                </div>
                <div class="gs-kpi-hint">{{ $this->isEnabled() ? 'Pelanggan melihat halaman maintenance' : 'Semua layanan bisa dipakai pelanggan' }}</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-device-tablet" /></span><span class="gs-kpi-label">Aplikasi kasir</span></div>
                <div class="gs-kpi-value">{{ $this->isEnabled() ? 'API ditutup' : 'API normal' }}</div>
                <div class="gs-kpi-hint">API membalas MAINTENANCE; transaksi offline dikirim setelah selesai</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-key" /></span><span class="gs-kpi-label">Akses admin</span></div>
                <div class="gs-kpi-value">{{ $bypass ? 'Lewat URL bypass' : 'Normal' }}</div>
                <div class="gs-kpi-hint">Admin yang mengaktifkan otomatis mendapat cookie bypass</div>
            </div>
        </div>

        @if ($bypass)
            <x-filament::section icon="heroicon-o-link" heading="URL bypass (admin lain)" description="Berlaku sampai maintenance dimatikan. Jangan dibagikan ke pelanggan">
                <p class="break-all font-mono text-xs">{{ $bypass }}</p>
            </x-filament::section>
        @endif

        <div class="grid gap-4 xl:grid-cols-2">
            <x-filament::section icon="heroicon-o-exclamation-triangle" heading="Yang terjadi saat maintenance" description="Berlaku untuk semua pelanggan sekaligus">
                <ul class="gs-checklist gs-checklist--plain">
                    <li class="gs-check is-warn"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-minus-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Halaman depan & pendaftaran</span><span class="gs-check-fix">Menampilkan halaman maintenance (503)</span></span></li>
                    <li class="gs-check is-warn"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-minus-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Dashboard owner & kasir web</span><span class="gs-check-fix">Tidak bisa dibuka sampai maintenance dimatikan</span></span></li>
                    <li class="gs-check is-warn"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-minus-circle" /></span><span class="gs-check-body"><span class="gs-check-label">API aplikasi kasir</span><span class="gs-check-fix">Membalas error MAINTENANCE; aplikasi menampilkan pesan maintenance</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Transaksi offline di tablet</span><span class="gs-check-fix">Tetap tersimpan dan dikirim otomatis setelah selesai</span></span></li>
                </ul>
            </x-filament::section>

            <x-filament::section icon="heroicon-o-clipboard-document-list" heading="Langkah aman" description="Untuk migrasi besar atau pembaruan aplikasi">
                <ol class="gs-checklist gs-checklist--plain">
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-circle-stack" /></span><span class="gs-check-body"><span class="gs-check-label">1. Backup dulu</span><span class="gs-check-fix">Sistem → Backup → Backup sekarang, lalu unduh</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-megaphone" /></span><span class="gs-check-body"><span class="gs-check-label">2. Umumkan jadwal</span><span class="gs-check-fix">Aplikasi → Pengumuman, beberapa jam sebelumnya</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-pause-circle" /></span><span class="gs-check-body"><span class="gs-check-label">3. Aktifkan maintenance</span><span class="gs-check-fix">Tombol kanan atas, konfirmasi kata sandi</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-play-circle" /></span><span class="gs-check-body"><span class="gs-check-label">4. Matikan setelah selesai</span><span class="gs-check-fix">Cek dashboard & aplikasi kasir kembali normal</span></span></li>
                </ol>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
