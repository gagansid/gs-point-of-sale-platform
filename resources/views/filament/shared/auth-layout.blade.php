{{--
    Layout halaman login panel Filament (App\Filament\Shared\Auth\Login): sama dengan x-auth.layout — latar navy,
    panel brand, kartu putih berisi form Filament, versi aplikasi di bawah kartu.
--}}
@php
    $brand = $livewire?->authBrand() ?? ['app' => 'Panel', 'heading' => '', 'text' => '', 'points' => []];
@endphp
<x-filament-panels::layout.base :livewire="$livewire">
    <main class="cashier-auth fi-auth-shell">
        <div class="cashier-auth-inner">
            <section class="cashier-auth-brand" aria-hidden="true">
                <x-site.logo light />
                <h2>{{ $brand['heading'] }}</h2>
                <p>{{ $brand['text'] }}</p>
                <ul>
                    @foreach ($brand['points'] as [$icon, $label])
                        <li><x-site.icon :name="$icon" /> {{ $label }}</li>
                    @endforeach
                </ul>
            </section>

            <div class="cashier-auth-column">
                <div class="cashier-auth-card">
                    {{ $slot }}
                </div>
                <p class="cashier-auth-version">gs.POS {{ $brand['app'] }} · v{{ config('app.version') }}</p>
            </div>
        </div>
    </main>
</x-filament-panels::layout.base>
