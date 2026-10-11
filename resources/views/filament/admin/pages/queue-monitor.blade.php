{{-- Sistem → Queue: ringkasan antrean + tabel job gagal. --}}
<x-filament-panels::page>
    @php($pending = $this->pending())
    <x-filament::section icon="heroicon-o-queue-list" heading="Antrean"
        description="Dijalankan cron setiap menit (queue:work --stop-when-empty). Antrean yang terus menumpuk berarti cron belum berjalan.">
        <dl class="grid gap-6 sm:grid-cols-2">
            <div>
                <dt class="text-[13px] font-medium text-gray-500 dark:text-gray-400">Menunggu</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($pending['pending'], 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt class="text-[13px] font-medium text-gray-500 dark:text-gray-400">Job tertua</dt>
                <dd class="mt-1 text-sm">{{ $pending['oldest'] ?? '—' }}</dd>
            </div>
        </dl>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
