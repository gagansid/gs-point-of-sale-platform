{{-- Sistem → Keamanan (section.md, notification.md) --}}
<x-filament-panels::page>
    <x-filament::section
        icon="heroicon-o-shield-check"
        heading="Verifikasi dua langkah (2FA)"
        description="Kode 6 digit dari aplikasi authenticator (Google Authenticator, Microsoft Authenticator, 1Password, dsb.) selain kata sandi."
    >
        <dl class="grid gap-6 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Kebijakan untuk semua super admin</dt>
                <dd class="mt-2">
                    @if ($this->isTwoFactorRequired())
                        <x-filament::badge color="success" icon="heroicon-o-lock-closed">Wajib</x-filament::badge>
                    @else
                        <x-filament::badge color="warning" icon="heroicon-o-lock-open">Tidak wajib</x-filament::badge>
                    @endif
                </dd>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    @if ($this->isTwoFactorRequired())
                        Admin tanpa 2FA wajib mengaturnya sebelum bisa memakai panel.
                    @else
                        Admin boleh login hanya dengan kata sandi. Disarankan tetap mewajibkan 2FA di production.
                    @endif
                </p>
            </div>

            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Akun Anda</dt>
                <dd class="mt-2">
                    @if ($this->currentAdminHasTwoFactor())
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">2FA aktif</x-filament::badge>
                    @else
                        <x-filament::badge color="gray" icon="heroicon-o-minus-circle">2FA belum aktif</x-filament::badge>
                    @endif
                </dd>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Aktifkan atau matikan 2FA akun Anda dari
                    <x-filament::link :href="filament()->getProfileUrl()">halaman profil</x-filament::link>.
                </p>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
