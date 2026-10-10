{{-- Baris audit: tanggal dibuat & terakhir diubah (AuditInfo). Zona waktu = zona panel (outlet aktif). --}}
@php
    use Filament\Support\Facades\FilamentTimezone;

    $record = $getRecord();
    $tz = FilamentTimezone::get();
    $created = $record?->created_at?->copy()->setTimezone($tz);
    $updated = $record?->updated_at?->copy()->setTimezone($tz);
    $edited = $updated !== null && $created !== null && $updated->gt($created->copy()->addSecond());
    $stacked ??= false;
@endphp

@if ($created)
    <div @class(['gs-audit', 'gs-audit--stacked' => $stacked]) role="contentinfo" aria-label="Riwayat data">
        <span class="gs-audit-item">
            <x-filament::icon icon="heroicon-o-calendar" class="gs-audit-icon" />
            <span>Dibuat</span>
            <time datetime="{{ $created->toIso8601String() }}" title="{{ $created->translatedFormat('l, j F Y H.i') }}">{{ $created->translatedFormat('j M Y, H.i') }}</time>
        </span>

        <span class="gs-audit-sep" aria-hidden="true"></span>

        <span class="gs-audit-item">
            <x-filament::icon icon="heroicon-o-pencil" class="gs-audit-icon" />
            @if ($edited)
                <span>Diubah</span>
                <time datetime="{{ $updated->toIso8601String() }}" title="{{ $updated->translatedFormat('l, j F Y H.i') }}">{{ $updated->diffForHumans() }}</time>
            @else
                <span>Belum pernah diubah</span>
            @endif
        </span>
    </div>
@endif
