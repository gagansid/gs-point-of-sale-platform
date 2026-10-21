{{-- Riwayat perubahan → Detail: siapa, kapan, data apa, lalu tabel kolom | sebelum | sesudah (nilai lengkap). --}}
@php
    /** @var \App\Models\ActivityLog $log */
    /** @var list<array{field: string, label: string, old: string, new: string}> $changes */
    $tz ??= \App\Support\CurrentOutlet::timezone();
    $when = $log->created_at?->setTimezone($tz)->locale('id');
    $properties = $log->properties;
@endphp
<div class="gs-audit-detail">
    <dl class="gs-nota-meta">
        <div><dt>Waktu</dt><dd>{{ $when?->translatedFormat('l, j F Y') }}<br><span class="gs-audit-sub">pukul {{ $when?->format('H.i.s') }} ({{ $tz }})</span></dd></div>
        <div><dt>Oleh</dt><dd>{{ $causer }}<br><span class="gs-audit-sub">{{ $role }}</span></dd></div>
        <div><dt>Aksi</dt><dd><x-filament::badge :color="$eventColor">{{ $event }}</x-filament::badge></dd></div>
        <div><dt>Jenis data</dt><dd>{{ $subject }}</dd></div>
        <div><dt>Nama data</dt><dd>{{ $name }}</dd></div>
        @if (filled($properties['outlet'] ?? null))
            <div><dt>Outlet</dt><dd>{{ $properties['outlet'] }}</dd></div>
        @endif
    </dl>

    @if ($log->event === \App\Actions\Product\SetProductAvailability::LOG_EVENT && $log->description)
        <div class="gs-callout is-info">{{ $log->description }}</div>
    @endif

    <table class="gs-detail-table gs-audit-table">
        <thead>
            <tr><th>Kolom</th><th>Sebelum</th><th class="gs-audit-arrow-col"></th><th>Sesudah</th></tr>
        </thead>
        <tbody>
            @forelse ($changes as $change)
                <tr wire:key="chg-{{ $change['field'] }}">
                    <td class="gs-detail-name">{{ $change['label'] }}</td>
                    <td><span @class(['gs-diff-val is-old', 'is-empty' => $change['old'] === '—'])>{{ $change['old'] }}</span></td>
                    <td class="gs-audit-arrow-col">→</td>
                    <td><span @class(['gs-diff-val is-new', 'is-empty' => $change['new'] === '—'])>{{ $change['new'] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="gs-detail-empty">Tanpa rincian kolom</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="gs-audit-foot">
        <span>ID log <span class="font-mono">{{ $log->id }}</span></span>
        @if ($log->subject_id)
            <span>ID data <span class="font-mono">{{ $log->subject_id }}</span></span>
        @endif
    </div>
</div>
