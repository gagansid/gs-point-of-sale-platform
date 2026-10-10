{{-- Logo gs.POS (sama dengan filament.shared.brand-logo); $light = versi di latar gelap. --}}
@props(['light' => false])

<span {{ $attributes->class(['site-logo', 'site-logo-light' => $light]) }}>
    <svg class="site-logo-mark" viewBox="9.75 9.75 44.5 44.75" aria-hidden="true">
        <rect class="bm-frame" x="14" y="14" width="36" height="27" rx="5" fill="none" stroke-width="4.5" />
        <path class="bm-accent" d="M22.5 27.5h9" stroke-width="4.5" stroke-linecap="round" />
        <circle class="bm-dot" cx="40.5" cy="27.5" r="3" />
        <path class="bm-accent" d="M22 50h20" stroke-width="5" stroke-linecap="round" />
    </svg>
    <span class="site-logo-word"><span class="w-gs">gs</span>.POS</span>
</span>
