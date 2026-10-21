{{-- Merek sidebar dashboard (Q128): logo bisnis bila diunggah owner, selain itu logo gs.POS. --}}
@php
    $user = \Filament\Facades\Filament::auth()->user();
    $logo = $user instanceof \App\Models\User ? \App\Support\TenantAppearance::logo($user->tenant) : null;
@endphp
@if ($logo)
    <span class="tenant-brand">
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logo) }}" alt="{{ $user->tenant->name }}" class="tenant-brand-logo">
        <span class="tenant-brand-name">{{ $user->tenant->name }}</span>
    </span>
@else
    @include('filament.shared.brand-logo')
@endif
