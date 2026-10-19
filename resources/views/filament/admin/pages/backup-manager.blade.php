{{-- Sistem → Backup: kartu ringkas, peringatan enkripsi, daftar file backup (unduh / hapus). --}}
@php
    $backups = $this->backups();
    $tz = \Filament\Support\Facades\FilamentTimezone::get();
    $at = fn (int $ts) => \Carbon\CarbonImmutable::createFromTimestamp($ts)->setTimezone($tz)->locale('id');
    $latest = collect($backups)->max('created');
    $stale = $latest === null || $latest < now()->subDays(2)->getTimestamp();
    $total = (int) collect($backups)->sum('size');
@endphp
<x-filament-panels::page>
    <div class="gs-overview">
        @unless ($this->isEncrypted())
            <div class="gs-banner gs-banner-warning" role="alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="gs-banner-icon" />
                <div><strong>Backup tidak terenkripsi.</strong> Isi <code>BACKUP_ARCHIVE_PASSWORD</code> di .env production: file backup berisi seluruh data pelanggan.</div>
            </div>
        @endunless

        <div class="gs-kpi-grid">
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-clock" /></span><span class="gs-kpi-label">Backup terakhir</span></div>
                <div class="gs-kpi-value">
                    @if ($latest === null)
                        <x-filament::badge color="danger" icon="heroicon-o-x-circle">Belum ada</x-filament::badge>
                    @elseif ($stale)
                        <x-filament::badge color="warning" icon="heroicon-o-exclamation-triangle">{{ $at($latest)->diffForHumans() }}</x-filament::badge>
                    @else
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">{{ $at($latest)->diffForHumans() }}</x-filament::badge>
                    @endif
                </div>
                <div class="gs-kpi-hint">{{ $latest !== null ? $at($latest)->translatedFormat('j M Y, H.i') : 'Klik "Backup sekarang"' }}</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-archive-box" /></span><span class="gs-kpi-label">Jumlah file</span></div>
                <div class="gs-kpi-value tabular-nums">{{ count($backups) }}</div>
                <div class="gs-kpi-hint">File lama dibersihkan otomatis tiap malam</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-circle-stack" /></span><span class="gs-kpi-label">Total ukuran</span></div>
                <div class="gs-kpi-value tabular-nums">{{ \Illuminate\Support\Number::fileSize($total, 1) }}</div>
                <div class="gs-kpi-hint">Disimpan privat di storage/app/backups</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-lock-closed" /></span><span class="gs-kpi-label">Enkripsi</span></div>
                <div class="gs-kpi-value">
                    @if ($this->isEncrypted())
                        <x-filament::badge color="success" icon="heroicon-o-lock-closed">Terenkripsi</x-filament::badge>
                    @else
                        <x-filament::badge color="warning" icon="heroicon-o-lock-open">Tidak terenkripsi</x-filament::badge>
                    @endif
                </div>
                <div class="gs-kpi-hint">Zip berkata sandi (BACKUP_ARCHIVE_PASSWORD)</div>
            </div>
        </div>

        <x-filament::section icon="heroicon-o-circle-stack" heading="File backup"
            description="Database + gambar produk. Backup di server yang sama tidak melindungi dari kerusakan server: unduh berkala">
            @if ($backups === [])
                <p class="gs-detail-empty">Belum ada backup.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="gs-detail-table">
                        <thead>
                            <tr><th>File</th><th>Waktu</th><th class="is-num">Ukuran</th><th class="is-end"><span class="sr-only">Aksi</span></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($backups as $backup)
                                <tr wire:key="b-{{ $backup['name'] }}">
                                    <td class="font-mono text-xs">{{ $backup['name'] }}</td>
                                    <td>{{ $at($backup['created'])->translatedFormat('j M Y, H.i') }} <span class="gs-detail-sub">· {{ $at($backup['created'])->diffForHumans() }}</span></td>
                                    <td class="is-num">{{ \Illuminate\Support\Number::fileSize($backup['size'], 1) }}</td>
                                    <td class="is-end">
                                        <div class="gs-row-actions">
                                            <x-filament::icon-button icon="heroicon-o-arrow-down-tray" color="gray" label="Unduh" tooltip="Unduh"
                                                wire:click="download('{{ $backup['name'] }}')" />
                                            {{ ($this->deleteAction)(['name' => $backup['name']]) }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
