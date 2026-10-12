{{-- Langkah 3: aplikasi — URL, edisi, zona waktu, subdomain, email SMTP (opsional) --}}
<x-install.layout title="Aplikasi" :step="3" subtitle="Pengaturan dasar. Semua bisa diubah nanti di file .env.">
    <form method="POST" action="{{ route('install.application.save') }}" class="site-form" novalidate>
        @csrf
        <div class="site-form-grid">
            <x-site.field name="app_name" label="Nama aplikasi" required :value="$saved['app_name']" maxlength="50" />
            <x-site.field name="app_url" label="URL aplikasi" type="url" required :value="$saved['app_url']" hint="Alamat utama, mis. https://gspos.id" />
        </div>

        <div class="site-form-grid">
            <div class="site-field">
                <label for="edition">Edisi<span class="site-required" aria-hidden="true">*</span></label>
                <select id="edition" name="edition" required>
                    <option value="saas" @selected(old('edition', $saved['edition']) === 'saas')>SaaS (banyak bisnis)</option>
                    <option value="self_hosted" @selected(old('edition', $saved['edition']) === 'self_hosted')>Jual putus (satu bisnis)</option>
                </select>
                @error('edition') <p class="site-error">{{ $message }}</p> @enderror
            </div>
            <div class="site-field">
                <label for="timezone">Zona waktu outlet<span class="site-required" aria-hidden="true">*</span></label>
                <select id="timezone" name="timezone" required>
                    @foreach ($timezones as $value => $label)
                        <option value="{{ $value }}" @selected(old('timezone', $saved['timezone']) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('timezone') <p class="site-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset class="install-optional">
            <legend>
                <label class="site-check">
                    <input type="hidden" name="use_subdomains" value="0">
                    <input type="checkbox" name="use_subdomains" value="1" @checked(old('use_subdomains', $saved['use_subdomains'] ?? false))>
                    Pakai subdomain terpisah (dashboard, admin, API)
                </label>
            </legend>
            <p class="site-field-hint">Tanpa subdomain: satu domain dengan /dashboard, /admin, /api/v1. Subdomain harus sudah dibuat di cPanel dengan document root folder <code>public</code>.</p>
            <div class="install-optional-body site-form">
                <x-site.field name="app_domain" label="Dashboard" :value="$saved['app_domain']" />
                <div class="site-form-grid">
                    <x-site.field name="admin_domain" label="Panel admin" :value="$saved['admin_domain']" />
                    <x-site.field name="api_domain" label="API" :value="$saved['api_domain']" />
                </div>
            </div>
        </fieldset>

        <fieldset class="install-optional">
            <legend>
                <label class="site-check">
                    <input type="checkbox" name="use_mail" value="1" @checked(old('use_mail', filled($saved['mail_host'] ?? null)))>
                    Atur email SMTP sekarang
                </label>
            </legend>
            <p class="site-field-hint">Untuk verifikasi email & lupa kata sandi. Bisa dilewati: email dicatat di log sampai SMTP diisi di .env.</p>
            <div class="install-optional-body site-form">
                <div class="site-form-grid install-grid-host">
                    <x-site.field name="mail_host" label="Host SMTP" :value="$saved['mail_host'] ?? ''" placeholder="mail.domain-anda.com" />
                    <x-site.field name="mail_port" label="Port" type="number" :value="$saved['mail_port']" />
                </div>
                <div class="site-form-grid">
                    <x-site.field name="mail_username" label="User SMTP" autocomplete="off" :value="$saved['mail_username'] ?? ''" />
                    <x-site.field name="mail_password" label="Kata sandi SMTP" type="password" autocomplete="new-password"
                        :placeholder="filled($saved['mail_password'] ?? null) ? '•••••• tersimpan' : ''" />
                </div>
                <div class="site-form-grid">
                    <div class="site-field">
                        <label for="mail_encryption">Enkripsi</label>
                        <select id="mail_encryption" name="mail_encryption">
                            @foreach (['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'Tanpa enkripsi'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('mail_encryption', $saved['mail_encryption']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-site.field name="mail_from_address" label="Email pengirim" type="email" :value="$saved['mail_from_address'] ?? ''" placeholder="no-reply@domain-anda.com" />
                </div>
            </div>
        </fieldset>

        <div class="install-actions">
            <a href="{{ route('install.database') }}" class="site-btn site-btn-ghost">Kembali</a>
            <button type="submit" class="site-btn site-btn-primary">Lanjut</button>
        </div>
    </form>
</x-install.layout>
