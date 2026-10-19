{{-- Sistem → Log. Semua isi log di-escape (bisa berisi input pengguna). --}}
@php
    $files = \App\Support\LogReader::files();
    $entries = $this->entries();
    $colors = ['emergency' => 'danger', 'alert' => 'danger', 'critical' => 'danger', 'error' => 'danger', 'warning' => 'warning', 'notice' => 'info', 'info' => 'info', 'debug' => 'gray'];
@endphp
<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-funnel" heading="Pilih log" description="File, level, dan kata kunci">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="file" aria-label="File log">
                    @forelse ($files as $name => $info)
                        <option value="{{ $name }}">{{ $name }} ({{ \Illuminate\Support\Number::fileSize($info['size']) }})</option>
                    @empty
                        <option value="">Belum ada file log</option>
                    @endforelse
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="level" aria-label="Level">
                    <option value="">Semua level</option>
                    @foreach (\App\Support\LogReader::LEVELS as $lvl)
                        <option value="{{ $lvl }}">{{ ucfirst($lvl) }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.500ms="search" placeholder="Cari teks…" aria-label="Cari" />
            </x-filament::input.wrapper>
        </div>
        <p class="mt-2 text-[13px] text-gray-500 dark:text-gray-400">200 entri terbaru dari 1 MB terakhir file. Unduh untuk melihat seluruh isi.</p>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-document-text" heading="Entri log" description="Klik entri untuk melihat detail & stack trace">
        @forelse ($entries as $i => $entry)
            <details class="border-b border-gray-100 py-2 last:border-0 dark:border-white/10" wire:key="log-{{ $i }}-{{ md5($entry['time'].$entry['message']) }}">
                <summary class="flex cursor-pointer items-start gap-3 text-sm">
                    <x-filament::badge :color="$colors[$entry['level']] ?? 'gray'">{{ strtoupper($entry['level']) }}</x-filament::badge>
                    <span class="shrink-0 font-mono text-xs text-gray-500">{{ $entry['time'] }}</span>
                    <span class="min-w-0 break-words">{{ \Illuminate\Support\Str::limit($entry['message'], 300) }}</span>
                </summary>
                <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-relaxed whitespace-pre-wrap text-gray-100">{{ $entry['message'] }}
{{ $entry['context'] }}</pre>
            </details>
        @empty
            <p class="text-sm text-gray-500">Tidak ada entri log.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
