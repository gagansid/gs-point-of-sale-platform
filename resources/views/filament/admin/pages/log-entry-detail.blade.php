{{-- Detail entri log (modal). Isi log di-escape: bisa berisi input pengguna. --}}
@php
    /** @var array<string, mixed> $entry */
    $trace = $entry['trace'];
@endphp
<div class="gs-invoice-detail">
    <dl class="gs-nota-meta">
        <div><dt>Level</dt><dd><x-filament::badge :color="$color">{{ strtoupper($entry['level']) }}</x-filament::badge></dd></div>
        <div><dt>Waktu</dt><dd>{{ $time->translatedFormat('j M Y, H.i.s') }} · {{ $time->diffForHumans() }}</dd></div>
        <div><dt>Lingkungan · file</dt><dd>{{ $entry['env'] }} · {{ $file }}</dd></div>
    </dl>

    <div class="grid gap-2">
        <div class="gs-log-label">Pesan</div>
        <pre class="gs-log-pre">{{ $entry['text'] }}</pre>
    </div>

    @if ($entry['exception'] !== null)
        <div class="grid gap-2">
            <div class="gs-log-label">Exception</div>
            <pre class="gs-log-pre">{{ $entry['exception'] }}</pre>
        </div>
    @endif

    @if ($entry['data'] !== null)
        <div class="grid gap-2">
            <div class="gs-log-label">Data</div>
            <pre class="gs-log-pre">{{ $entry['data'] }}</pre>
        </div>
    @endif

    <div class="grid gap-2">
        <div class="gs-log-label">Stack trace</div>
        @if ($trace !== null && $trace !== '')
            <pre class="gs-log-pre gs-log-pre--dark">{{ $trace }}</pre>
        @else
            <p class="gs-detail-empty">Tidak ada stack trace</p>
        @endif
    </div>
</div>
