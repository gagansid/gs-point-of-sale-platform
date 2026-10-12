{{-- Kerangka installer /install (ADR 0016): kartu lebar + penanda langkah. --}}
@props(['title', 'step' => 0, 'subtitle' => null])

@php
    $steps = ['Syarat', 'Database', 'Aplikasi', 'Akun', 'Instal'];
@endphp

<x-site.layout :title="$title.' — Instal gs.POS'">
    <main class="site-auth">
        <div class="site-auth-card install-card">
            <div class="site-auth-logo"><x-site.logo /></div>

            @if ($step > 0)
                <ol class="install-steps" aria-label="Langkah instalasi">
                    @foreach ($steps as $i => $label)
                        <li @class(['is-done' => $i + 1 < $step, 'is-current' => $i + 1 === $step]) @if ($i + 1 === $step) aria-current="step" @endif>
                            <span>{{ $i + 1 }}</span>{{ $label }}
                        </li>
                    @endforeach
                </ol>
            @endif

            <h1>{{ $title }}</h1>
            @if ($subtitle)
                <p class="site-auth-sub">{{ $subtitle }}</p>
            @endif

            @if (session('install.status'))
                <div class="site-alert site-alert-success" role="status">{{ session('install.status') }}</div>
            @endif

            {{ $slot }}
        </div>
    </main>
</x-site.layout>
