{{-- Isi kotak notifikasi (dropdown lonceng & halaman): kepala + tab, daftar, kaki --}}
<div class="gs-alerts-head">
    <span>{{ $isPage ? 'Kotak masuk' : 'Notifikasi' }} @if ($unread > 0)<span class="gs-alerts-count">{{ $unread }}</span>@endif</span>
    @if ($unread > 0)
        <button type="button" class="gs-inbox-link" wire:click="markAllRead">Tandai semua dibaca</button>
    @endif
</div>

<div class="gs-inbox-tabs" role="tablist" aria-label="Filter notifikasi">
    @foreach ($tabs as $value => $label)
        <button type="button" role="tab" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
            @class(['gs-inbox-tab', 'is-active' => $tab === $value]) wire:click="setTab('{{ $value }}')">{{ $label }}</button>
    @endforeach
</div>

@if ($items->isEmpty())
    <div class="gs-alerts-empty">
        <x-filament::icon icon="heroicon-o-check-circle" />
        <p>{{ $tab === 'unread' ? 'Semua sudah dibaca. Tidak ada notifikasi baru.' : 'Belum ada notifikasi.' }}</p>
    </div>
@else
    <ul @class(['gs-alerts-list', 'is-page' => $isPage])>
        @foreach ($items as $notification)
            @php
                $data = $notification->data;
                $isUnread = $notification->read_at === null;
                $isDone = ! ($data['active'] ?? false);
            @endphp
            <li wire:key="n-{{ $notification->id }}" @class(['gs-inbox-row', 'is-unread' => $isUnread])>
                <button type="button" class="gs-alerts-item" wire:click="open('{{ $notification->id }}')">
                    <span class="gs-alerts-icon is-{{ $isDone ? 'done' : ($data['color'] ?? 'info') }}">
                        <x-filament::icon :icon="$isDone ? 'heroicon-o-check' : ($data['icon'] ?? 'heroicon-o-bell')" />
                    </span>
                    <span class="gs-alerts-text">
                        <span class="gs-alerts-title">{{ $data['title'] ?? 'Notifikasi' }}</span>
                        <span class="gs-alerts-desc">{{ $data['description'] ?? '' }}</span>
                        <span class="gs-inbox-time">
                            {{ $notification->created_at === null || $notification->created_at->diffInSeconds(now()) < 60 ? 'Baru saja' : $notification->created_at->locale('id')->diffForHumans() }}
                            @if ($isDone) · Sudah selesai @endif
                        </span>
                    </span>
                    @if ($isUnread)
                        <span class="gs-inbox-dot" aria-label="Belum dibaca"></span>
                    @endif
                </button>
                <div class="gs-inbox-actions">
                    <button type="button" class="gs-inbox-icon-btn" wire:click="toggleRead('{{ $notification->id }}')"
                        aria-label="{{ $isUnread ? 'Tandai dibaca' : 'Tandai belum dibaca' }}"
                        x-tooltip="{ content: @js($isUnread ? 'Tandai dibaca' : 'Tandai belum dibaca'), theme: $store.theme }">
                        <x-filament::icon :icon="$isUnread ? 'heroicon-o-envelope-open' : 'heroicon-o-envelope'" />
                    </button>
                    <button type="button" class="gs-inbox-icon-btn is-danger" wire:click="remove('{{ $notification->id }}')"
                        aria-label="Hapus notifikasi" x-tooltip="{ content: 'Hapus', theme: $store.theme }">
                        <x-filament::icon icon="heroicon-o-trash" />
                    </button>
                </div>
            </li>
        @endforeach
    </ul>
@endif

@if ($isPage)
    @if ($notifications->hasPages())
        <div class="gs-inbox-pages">{{ $notifications->links() }}</div>
    @endif
    @if ($items->isNotEmpty())
        <div class="gs-inbox-foot">
            <button type="button" class="gs-inbox-link is-danger" wire:click="clearAll" wire:confirm="Hapus semua notifikasi?">Bersihkan semua</button>
        </div>
    @endif
@else
    <div class="gs-inbox-foot">
        <a href="{{ $pageUrl }}" class="gs-inbox-link">Lihat semua notifikasi</a>
        @if ($items->isNotEmpty())
            <button type="button" class="gs-inbox-link is-danger" wire:click="clearAll" wire:confirm="Hapus semua notifikasi?">Bersihkan semua</button>
        @endif
    </div>
@endif
