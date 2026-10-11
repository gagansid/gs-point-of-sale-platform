{{-- Pengumuman super admin (Aplikasi → Pengumuman) di atas setiap halaman dashboard. Bisa ditutup per browser. --}}
@foreach (\App\Models\Announcement::current() as $announcement)
    <div class="gs-banner gs-banner-info" role="status" wire:ignore
        x-data="{ key: 'gspos-ann-{{ $announcement->id }}', hidden: false }"
        x-init="hidden = localStorage.getItem(key) === '1'" x-show="! hidden">
        <x-filament::icon icon="heroicon-o-megaphone" class="gs-banner-icon" />
        <div>
            <strong>{{ $announcement->title }}</strong>
            {{ $announcement->body }}
        </div>
        <button type="button" class="gs-banner-action" x-on:click="localStorage.setItem(key, '1'); hidden = true">Tutup</button>
    </div>
@endforeach
