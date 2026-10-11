{{-- Sistem → Maintenance --}}
<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-wrench-screwdriver" heading="Status layanan"
        description="Maintenance mematikan sementara halaman depan, dashboard, kasir web, dan API untuk semua pelanggan.">
        <dl class="grid gap-6 sm:grid-cols-2">
            <div>
                <dt class="text-[13px] font-medium text-gray-500 dark:text-gray-400">Status</dt>
                <dd class="mt-2">
                    @if ($this->isEnabled())
                        <x-filament::badge color="danger" icon="heroicon-o-pause-circle">Maintenance aktif</x-filament::badge>
                    @else
                        <x-filament::badge color="success" icon="heroicon-o-play-circle">Normal</x-filament::badge>
                    @endif
                </dd>
            </div>
            @if ($bypass = $this->bypassUrl())
                <div>
                    <dt class="text-[13px] font-medium text-gray-500 dark:text-gray-400">URL bypass (admin lain)</dt>
                    <dd class="mt-2 break-all font-mono text-xs">{{ $bypass }}</dd>
                    <p class="mt-2 text-[13px] text-gray-500 dark:text-gray-400">Jangan dibagikan ke pelanggan. Berlaku sampai maintenance dimatikan.</p>
                </div>
            @endif
        </dl>
    </x-filament::section>
</x-filament-panels::page>
