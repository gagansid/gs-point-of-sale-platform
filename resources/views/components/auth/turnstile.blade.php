{{-- Cloudflare Turnstile (Pengaturan → Keamanan, per halaman login). Tidak dirender bila tidak aktif. --}}
@props(['audience'])
@if (app(\App\Services\Security\Turnstile::class)->enabled($audience))
    <div class="auth-turnstile">
        <div class="cf-turnstile" data-sitekey="{{ \App\Services\Security\Turnstile::siteKey() }}" data-language="id" data-theme="light" data-size="flexible"></div>
        {{-- Panel Filament mode gelap (kelas .dark sudah dipasang di <head>): widget ikut gelap; halaman situs tetap terang --}}
        <script>
            if (document.documentElement.classList.contains('dark')) {
                document.currentScript.previousElementSibling.dataset.theme = 'dark';
            }
        </script>
        @error(\App\Services\Security\Turnstile::FIELD) <p class="cashier-field-error">{{ $message }}</p> @enderror
    </div>
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
