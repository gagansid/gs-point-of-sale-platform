{{-- Sistem → Keamanan (section.md, notification.md): 2FA, pemeriksaan konfigurasi, admin, perlindungan bawaan. --}}
@php
    $checks = $this->checklist();
    $passed = count(array_filter($checks, fn (array $c): bool => $c['ok']));
    $admins = $this->admins();
@endphp
<x-filament-panels::page>
    <div class="gs-overview">
        <div class="gs-kpi-grid">
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-lock-closed" /></span><span class="gs-kpi-label">Wajib 2FA</span></div>
                <div class="gs-kpi-value">
                    @if ($this->isTwoFactorRequired())
                        <x-filament::badge color="success" icon="heroicon-o-lock-closed">Wajib</x-filament::badge>
                    @else
                        <x-filament::badge color="warning" icon="heroicon-o-lock-open">Tidak wajib</x-filament::badge>
                    @endif
                </div>
                <div class="gs-kpi-hint">{{ $this->isTwoFactorRequired() ? 'Admin tanpa 2FA wajib mengaturnya dulu' : 'Disarankan wajib di production' }}</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-user-circle" /></span><span class="gs-kpi-label">Akun Anda</span></div>
                <div class="gs-kpi-value">
                    @if ($this->currentAdminHasTwoFactor())
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">2FA aktif</x-filament::badge>
                    @else
                        <x-filament::badge color="gray" icon="heroicon-o-minus-circle">2FA belum aktif</x-filament::badge>
                    @endif
                </div>
                <div class="gs-kpi-hint">Atur di <x-filament::link :href="filament()->getProfileUrl()" size="sm">halaman profil</x-filament::link></div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-users" /></span><span class="gs-kpi-label">Super admin ber-2FA</span></div>
                <div class="gs-kpi-value tabular-nums">{{ $admins->filter(fn ($a) => $a->hasTwoFactorEnabled())->count() }} / {{ $admins->count() }}</div>
                <div class="gs-kpi-hint">Kode 6 digit aplikasi authenticator</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-clipboard-document-check" /></span><span class="gs-kpi-label">Pemeriksaan</span></div>
                <div class="gs-kpi-value tabular-nums">{{ $passed }} / {{ count($checks) }} lolos</div>
                <div class="gs-kpi-hint">{{ $passed === count($checks) ? 'Konfigurasi sudah aman' : 'Lihat langkah perbaikan di bawah' }}</div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <x-filament::section icon="heroicon-o-clipboard-document-check" heading="Pemeriksaan konfigurasi"
                description="Dibaca dari .env & setelan; ubah di server lalu muat ulang halaman" class="xl:col-span-2">
                <ul class="gs-checklist">
                    @foreach ($checks as $check)
                        <li wire:key="check-{{ $check['key'] }}" @class(['gs-check', 'is-ok' => $check['ok'], 'is-warn' => ! $check['ok']])>
                            <span class="gs-check-icon">
                                <x-filament::icon :icon="$check['ok'] ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" />
                            </span>
                            <span class="gs-check-body">
                                <span class="gs-check-label">{{ $check['label'] }}</span>
                                @unless ($check['ok'])
                                    <span class="gs-check-fix">{{ $check['fix'] }}</span>
                                @endunless
                            </span>
                            <span class="gs-check-value">{{ $check['value'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>

            <x-filament::section icon="heroicon-o-shield-check" heading="Perlindungan bawaan" description="Selalu aktif, tidak perlu diatur">
                <ul class="gs-checklist gs-checklist--plain">
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Batas percobaan login</span><span class="gs-check-fix">{{ $this->loginRateLimit() }}× per menit per IP & perangkat</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Token CSRF & cookie terenkripsi</span><span class="gs-check-fix">Semua form panel & halaman depan</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Konfirmasi kata sandi</span><span class="gs-check-fix">Ubah kebijakan 2FA & maintenance</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Guard admin terpisah</span><span class="gs-check-fix">Akun tenant tidak bisa membuka /admin</span></span></li>
                    <li class="gs-check is-ok"><span class="gs-check-icon"><x-filament::icon icon="heroicon-o-check-circle" /></span><span class="gs-check-body"><span class="gs-check-label">Honeypot & throttle daftar</span><span class="gs-check-fix">Halaman daftar & kontak sales</span></span></li>
                </ul>
            </x-filament::section>
        </div>

        @php($ts = $this->turnstileStatus())
        <x-filament::section icon="heroicon-o-shield-exclamation" heading="Cloudflare Turnstile" description="Kotak verifikasi anti-bot di bawah form login; diatur terpisah per halaman login">
            <x-slot name="afterHeader">
                {{ $this->turnstileAction }}
            </x-slot>
            @unless ($ts['configured'])
                <div class="gs-banner gs-banner-warning mb-4" role="alert">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="gs-banner-icon" />
                    <div>Kunci belum diisi. Tambahkan <code>TURNSTILE_SITE_KEY</code> & <code>TURNSTILE_SECRET_KEY</code> di .env (dash.cloudflare.com → Turnstile).</div>
                </div>
            @endunless
            <ul class="gs-checklist gs-checklist--plain">
                @foreach ($ts['audiences'] as $key => $row)
                    <li wire:key="ts-{{ $key }}" @class(['gs-check', 'is-ok' => $row['enabled'], 'is-warn' => ! $row['enabled']])>
                        <span class="gs-check-icon"><x-filament::icon :icon="$row['enabled'] ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle'" /></span>
                        <span class="gs-check-body"><span class="gs-check-label">{{ $row['label'] }}</span></span>
                        <span class="gs-check-value">
                            <x-filament::badge :color="$row['enabled'] ? 'success' : 'gray'">{{ $row['enabled'] ? 'Aktif' : 'Nonaktif' }}</x-filament::badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-users" heading="Super admin" description="Status 2FA & login terakhir setiap akun">
            <x-slot name="afterHeader">
                <x-filament::link :href="$this->adminsUrl()" size="sm">Kelola admin</x-filament::link>
            </x-slot>
            <div class="overflow-x-auto">
                <table class="gs-detail-table">
                    <thead><tr><th>Nama</th><th>2FA</th><th class="is-end">Login terakhir</th></tr></thead>
                    <tbody>
                        @foreach ($admins as $admin)
                            <tr wire:key="admin-{{ $admin->id }}">
                                <td><div class="gs-detail-name">{{ $admin->name }}</div><div class="gs-detail-sub">{{ $admin->email }}</div></td>
                                <td>
                                    @if ($admin->hasTwoFactorEnabled())
                                        <x-filament::badge color="success" icon="heroicon-o-check-circle">Aktif</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning" icon="heroicon-o-exclamation-triangle">Belum</x-filament::badge>
                                    @endif
                                </td>
                                <td class="is-end">{{ $admin->last_login_at?->locale('id')->diffForHumans() ?? 'Belum pernah' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
