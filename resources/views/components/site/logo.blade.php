{{-- Logo gs.POS (sama dengan filament.shared.brand-logo); $light = versi di latar gelap. --}}
@props(['light' => false])

<span {{ $attributes->class(['site-logo', 'site-logo-light' => $light]) }}>
    <svg class="site-logo-mark" viewBox="10 12 44 44" aria-hidden="true">
        <rect class="bm-frame" x="14" y="16" width="36" height="26" rx="4" fill="none" stroke-width="4.5" />
        <path class="bm-accent" d="M22 50h20" stroke-width="5" stroke-linecap="round" />
        <path class="bm-accent" d="M22 29h12" stroke-width="4.5" stroke-linecap="round" />
    </svg>
    <span class="site-logo-word"><span class="w-gs">gs</span>.POS</span>
</span>
