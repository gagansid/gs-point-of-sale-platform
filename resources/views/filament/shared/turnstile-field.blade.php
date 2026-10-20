{{-- Cloudflare Turnstile di form login Filament: token ditulis ke state field (tanpa request), reset setelah gagal. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:ignore class="auth-turnstile"
        x-data="{
            id: null,
            render() {
                if (! window.turnstile) { return setTimeout(() => this.render(), 200) }
                this.id = window.turnstile.render(this.$refs.box, {
                    sitekey: @js($siteKey), language: 'id', theme: 'light', size: 'flexible',
                    callback: (token) => $wire.set(@js($getStatePath()), token, false),
                    'expired-callback': () => $wire.set(@js($getStatePath()), null, false),
                })
            },
        }"
        x-init="render()"
        x-on:turnstile-reset.window="window.turnstile?.reset(id)">
        <div x-ref="box"></div>
    </div>
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    @endonce
</x-dynamic-component>
