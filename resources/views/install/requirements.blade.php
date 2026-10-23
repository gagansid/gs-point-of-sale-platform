{{-- Langkah 1: cek syarat server --}}
<x-install.layout title="Cek syarat server" :step="1" subtitle="Aplikasi belum terpasang di server ini. Kami cek dulu kesiapan hosting.">
    <ul class="install-checklist">
        @foreach ($items as $item)
            <li @class(['is-ok' => $item['ok'], 'is-fail' => ! $item['ok'] && $item['required'], 'is-warn' => ! $item['ok'] && ! $item['required']])>
                <span class="install-check-icon" aria-hidden="true">{{ $item['ok'] ? '✓' : ($item['required'] ? '✕' : '!') }}</span>
                <span>
                    <strong>{{ $item['label'] }}</strong>
                    <small>{{ $item['detail'] }}@if (! $item['required']) · opsional @endif</small>
                </span>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('install.requirements.accept') }}" class="install-actions">
        @csrf
        @error('requirements') <p class="site-error">{{ $message }}</p> @enderror
        <a href="{{ route('install.requirements') }}" class="site-btn site-btn-ghost">Cek ulang</a>
        <button type="submit" class="site-btn site-btn-primary" @disabled(! $passes)>Lanjut</button>
    </form>
</x-install.layout>
