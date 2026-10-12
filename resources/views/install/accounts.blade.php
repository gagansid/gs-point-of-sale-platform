{{-- Langkah 4: akun — super admin, bisnis + owner, manager (opsional) --}}
<x-install.layout title="Akun" :step="4" subtitle="Kata sandi tidak ditampilkan lagi setelah disimpan. Catat di tempat aman.">
    <form method="POST" action="{{ route('install.accounts.save') }}" class="site-form" novalidate>
        @csrf

        <fieldset class="install-section">
            <legend>Super admin <small>panel admin, wajib 2FA saat masuk pertama</small></legend>
            <div class="site-form-grid">
                <x-site.field name="admin_name" label="Nama" required :value="$saved['admin_name'] ?? ''" maxlength="100" />
                <x-site.field name="admin_email" label="Email" type="email" required autocomplete="off" :value="$saved['admin_email'] ?? ''" />
            </div>
            <div class="site-form-grid">
                <x-site.field name="admin_password" label="Kata sandi" type="password" required autocomplete="new-password" maxlength="255" />
                <x-site.field name="admin_password_confirmation" label="Ulangi kata sandi" type="password" required autocomplete="new-password" maxlength="255" />
            </div>
            <p class="site-field-hint">Minimal 12 karakter: huruf besar & kecil, angka, simbol.</p>
        </fieldset>

        <fieldset class="install-optional">
            <legend>
                @if ($selfHosted)
                    <input type="hidden" name="create_business" value="1">
                    <span class="install-legend">Bisnis & owner <small>wajib untuk edisi jual putus</small></span>
                @else
                    <label class="site-check">
                        <input type="hidden" name="create_business" value="0">
                        <input type="checkbox" name="create_business" value="1" @checked(old('create_business', $saved['create_business'] ?? true))>
                        Buat bisnis pertama & owner sekarang
                    </label>
                @endif
            </legend>
            <div @class(['install-optional-body' => ! $selfHosted, 'site-form'])>
                <div class="site-form-grid">
                    <x-site.field name="business_name" :required="$selfHosted" label="Nama bisnis" :value="$saved['business_name'] ?? ''" maxlength="100" placeholder="Kopi Senja" />
                    <div class="site-field">
                        <label for="business_type">Jenis usaha</label>
                        <select id="business_type" name="business_type">
                            @foreach ($businessTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('business_type', $saved['business_type'] ?? 'cafe') === $type->value)>{{ $type->getLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="site-form-grid">
                    <x-site.field name="owner_name" :required="$selfHosted" label="Nama owner" :value="$saved['owner_name'] ?? ''" maxlength="100" />
                    <x-site.field name="owner_email" :required="$selfHosted" label="Email owner (login dashboard)" type="email" autocomplete="off" :value="$saved['owner_email'] ?? ''" />
                </div>
                <div class="site-form-grid">
                    <x-site.field name="owner_password" :required="$selfHosted" label="Kata sandi owner" type="password" autocomplete="new-password" maxlength="255" />
                    <x-site.field name="owner_password_confirmation" :required="$selfHosted" label="Ulangi kata sandi" type="password" autocomplete="new-password" maxlength="255" />
                </div>
                <p class="site-field-hint">Minimal 8 karakter, berisi huruf dan angka. Outlet pertama memakai nama bisnis.</p>

                <fieldset class="install-optional install-nested">
                    <legend>
                        <label class="site-check">
                            <input type="hidden" name="create_manager" value="0">
                            <input type="checkbox" name="create_manager" value="1" @checked(old('create_manager', $saved['create_manager'] ?? false))>
                            Tambah manager (opsional)
                        </label>
                    </legend>
                    <div class="install-optional-body site-form">
                        <div class="site-form-grid">
                            <x-site.field name="manager_name" label="Nama manager" :value="$saved['manager_name'] ?? ''" maxlength="100" />
                            <x-site.field name="manager_email" label="Email manager" type="email" autocomplete="off" :value="$saved['manager_email'] ?? ''" />
                        </div>
                        <div class="site-form-grid">
                            <x-site.field name="manager_password" label="Kata sandi manager" type="password" autocomplete="new-password" maxlength="255" />
                            <x-site.field name="manager_password_confirmation" label="Ulangi kata sandi" type="password" autocomplete="new-password" maxlength="255" />
                        </div>
                        <p class="site-field-hint">Manager menerima email verifikasi (butuh SMTP). Kasir & supervisor ditambahkan dari menu Karyawan.</p>
                    </div>
                </fieldset>
            </div>
        </fieldset>

        <div class="install-actions">
            <a href="{{ route('install.application') }}" class="site-btn site-btn-ghost">Kembali</a>
            <button type="submit" class="site-btn site-btn-primary">Lanjut</button>
        </div>
    </form>
</x-install.layout>
