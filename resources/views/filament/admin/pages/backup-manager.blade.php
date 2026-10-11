{{-- Sistem → Backup --}}
<x-filament-panels::page>
    @unless ($this->isEncrypted())
        <div class="gs-banner gs-banner-warning" role="alert">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="gs-banner-icon" />
            <div><strong>Backup tidak terenkripsi.</strong> Isi <code>BACKUP_ARCHIVE_PASSWORD</code> di .env production: file backup berisi seluruh data pelanggan.</div>
        </div>
    @endunless
    <x-filament::section icon="heroicon-o-circle-stack" heading="File backup"
        description="Database + gambar produk, disimpan privat di server (storage/app/backups). Unduh berkala ke tempat lain: backup di server yang sama tidak melindungi dari kerusakan server.">
        @php($backups = $this->backups())
        @if ($backups === [])
            <p class="text-sm text-gray-500">Belum ada backup.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[13px] text-gray-500">
                        <th class="py-2">File</th><th class="py-2">Waktu</th><th class="py-2 text-right">Ukuran</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr class="border-t border-gray-100 dark:border-white/10" wire:key="b-{{ $backup['name'] }}">
                            <td class="py-2 font-mono text-xs">{{ $backup['name'] }}</td>
                            <td class="py-2">{{ \Carbon\CarbonImmutable::createFromTimestamp($backup['created'])->setTimezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat('j M Y, H.i') }}</td>
                            <td class="py-2 text-right tabular-nums">{{ \Illuminate\Support\Number::fileSize($backup['size'], 1) }}</td>
                            <td class="py-2 text-right">
                                <x-filament::link tag="button" wire:click="download('{{ $backup['name'] }}')" icon="heroicon-o-arrow-down-tray">Unduh</x-filament::link>
                                <span class="mx-2 text-gray-300">|</span>
                                {{ ($this->deleteAction)(['name' => $backup['name']]) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
