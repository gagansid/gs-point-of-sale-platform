{{--
    Notifikasi panel (Q139, App\Livewire\Panel\NotificationInbox): mode `bell` = dropdown lonceng topbar,
    mode `page` = halaman Notifikasi. Item: ikon, judul, keterangan, waktu relatif, titik belum dibaca;
    klik = dibaca + buka menu terkait; ⋯ tandai dibaca/belum & hapus.
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \Illuminate\Notifications\DatabaseNotification>|\Illuminate\Pagination\LengthAwarePaginator $notifications */
    $isPage = $mode === 'page';
    $items = $isPage ? $notifications->getCollection() : $notifications;
    $tabs = ['unread' => 'Belum dibaca', 'all' => 'Semua'];
@endphp

<div @class(['gs-inbox', 'is-page' => $isPage])>
    @if ($isPage)
        @include('livewire.panel.partials.notification-inbox-body')
    @else
        {{-- Tanpa teleport: aksi Livewire di dalam panel dropdown tetap terhubung ke komponen ini --}}
        <x-filament::dropdown placement="bottom-end" width="sm" class="gs-alerts">
            <x-slot name="trigger">
                <button
                    type="button"
                    @class(['gs-theme-toggle gs-alerts-trigger', 'has-alerts' => $unread > 0])
                    aria-label="{{ $unread > 0 ? $unread.' notifikasi belum dibaca' : 'Notifikasi' }}"
                    x-tooltip="{ content: @js($unread > 0 ? $unread.' belum dibaca' : 'Notifikasi'), theme: $store.theme }"
                >
                    <x-filament::icon icon="heroicon-o-bell" />
                    @if ($unread > 0)
                        <span class="gs-alerts-badge">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </button>
            </x-slot>

            @include('livewire.panel.partials.notification-inbox-body')
        </x-filament::dropdown>
    @endif
</div>
