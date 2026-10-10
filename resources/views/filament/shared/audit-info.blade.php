{{--
    Baris/kartu audit (AuditInfo): dibuat & terakhir diubah beserta pelakunya (RecordsAuthor).
    "Diubah" relatif bila < 1 hari, selebihnya tanggal asli. Zona waktu = zona panel (outlet aktif).
--}}
@php
    use Filament\Support\Facades\FilamentTimezone;

    $record = $getRecord();
    $tz = FilamentTimezone::get();
    $created = $record?->created_at?->copy()->setTimezone($tz);
    $updated = $record?->updated_at?->copy()->setTimezone($tz);
    $edited = $updated !== null && $created !== null && $updated->gt($created->copy()->addSecond());
    $hasAuthor = $record !== null && method_exists($record, 'authorName');
    $creator = $hasAuthor ? $record->authorName('created_by') : null;
    $editor = $hasAuthor ? $record->authorName('updated_by') : null;
    $stacked ??= false;
    $format = 'j M Y, H.i';
@endphp

@if ($created)
    <div @class(['gs-audit', 'gs-audit--stacked' => $stacked]) role="contentinfo" aria-label="Riwayat data">
        <div class="gs-audit-item">
            <x-filament::icon icon="heroicon-o-calendar" class="gs-audit-icon" />
            <span class="gs-audit-label">Dibuat</span>
            <span class="gs-audit-value">
                <time datetime="{{ $created->toIso8601String() }}" title="{{ $created->translatedFormat('l, j F Y H.i') }}">{{ $created->translatedFormat($format) }}</time>
                @if ($creator)
                    <span class="gs-audit-by">oleh {{ $creator }}</span>
                @endif
            </span>
        </div>

        <span class="gs-audit-sep" aria-hidden="true"></span>

        <div class="gs-audit-item">
            <x-filament::icon icon="heroicon-o-pencil" class="gs-audit-icon" />
            <span class="gs-audit-label">Diubah</span>
            <span class="gs-audit-value">
                @if ($edited)
                    <time datetime="{{ $updated->toIso8601String() }}" title="{{ $updated->translatedFormat('l, j F Y H.i') }}">
                        {{ $updated->gt(now()->subDay()) ? $updated->diffForHumans() : $updated->translatedFormat($format) }}
                    </time>
                    @if ($editor)
                        <span class="gs-audit-by">oleh {{ $editor }}</span>
                    @endif
                @else
                    <span class="gs-audit-empty">Belum pernah</span>
                @endif
            </span>
        </div>
    </div>
@endif
