{{-- /cashier/profile: profil pengguna kasir web — data diri & keamanan. Gaya halaman kasir Q131. --}}
@php($initial = mb_strtoupper(mb_substr(trim($user->name), 0, 1)))
<x-cashier.layout title="Profil" active="profile">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <h1>Profil</h1>
                <p>Data diri dan keamanan akun kamu. Email & username kasir diatur owner di menu Karyawan.</p>
            </div>
        </header>

        <div class="pos-profile">
            {{-- Kiri: kartu profil + info akun --}}
            <section class="pos-card pos-profile-card">
                <div class="pos-profile-hero">
                    @if ($avatarUrl)
                        <img class="pos-avatar is-lg" src="{{ $avatarUrl }}" alt="Foto {{ $user->name }}">
                    @else
                        <span class="pos-avatar is-lg" aria-hidden="true">{{ $initial }}</span>
                    @endif
                    <strong>{{ $user->name }}</strong>
                    <span class="pos-badge is-open">{{ $user->role->getLabel() }}</span>
                </div>
                <dl class="pos-dl">
                    <div><dt>Bisnis</dt><dd>{{ $user->tenant->name ?? '—' }}</dd></div>
                    <div><dt>Outlet</dt><dd>{{ $outlets !== '' ? $outlets : '—' }}</dd></div>
                    <div><dt>Username</dt><dd>{{ $user->username ?? '—' }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $user->email ?? '—' }}</dd></div>
                    <div><dt>Terakhir masuk</dt><dd>{{ $user->last_login_at?->setTimezone(\App\Support\CurrentOutlet::timezone())->locale('id')->translatedFormat('j M Y, H.i') ?? '—' }}</dd></div>
                </dl>
            </section>

            <div class="pos-profile-forms">
                {{-- Data diri --}}
                <section class="pos-card">
                    <div class="pos-card-head"><h2>@svg('heroicon-o-user', ['aria-hidden' => 'true']) Data diri</h2></div>
                    <form method="POST" action="{{ route('cashier.profile.update') }}" enctype="multipart/form-data" class="pos-form" novalidate>
                        @csrf
                        {{-- Foto: pratinjau langsung sebelum disimpan, tombol Unggah/Hapus/Batal (JS kecil di bawah, tanpa Alpine) --}}
                        <div class="pos-avatar-field" data-avatar-field>
                            <label class="pos-avatar-pick" for="avatar" title="Ganti foto">
                                <img class="pos-avatar is-md" src="{{ $avatarUrl ?? '' }}" alt="" data-avatar-preview data-original="{{ $avatarUrl ?? '' }}" @if (! $avatarUrl) hidden @endif>
                                <span class="pos-avatar is-md" aria-hidden="true" data-avatar-initial @if ($avatarUrl) hidden @endif>{{ $initial }}</span>
                                <span class="pos-avatar-badge" aria-hidden="true">@svg('heroicon-s-camera')</span>
                            </label>
                            <div class="pos-avatar-body">
                                <strong>Foto profil</strong>
                                <small class="pos-field-hint" data-avatar-status>JPG, PNG, atau WEBP · maks. 2 MB · tampil bulat</small>
                                <div class="pos-avatar-actions">
                                    <label class="site-btn site-btn-outline" for="avatar">@svg('heroicon-o-arrow-up-tray', ['aria-hidden' => 'true']) {{ $avatarUrl ? 'Ganti foto' : 'Unggah foto' }}</label>
                                    @if ($avatarUrl)
                                        <button type="button" class="site-btn pos-btn-outline-danger" data-avatar-remove>@svg('heroicon-o-trash', ['aria-hidden' => 'true']) Hapus</button>
                                    @endif
                                    <button type="button" class="site-btn site-btn-outline" data-avatar-cancel hidden>Batal</button>
                                </div>
                                @error('avatar') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </div>
                            <input id="avatar" name="avatar" type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" data-avatar-input @error('avatar') aria-invalid="true" @enderror>
                            <input type="hidden" name="remove_avatar" value="0" data-avatar-remove-input>
                        </div>
                        <div class="pos-field-row">
                            <label class="pos-field" for="name"><span>Nama<span class="site-required" aria-hidden="true">*</span></span>
                                <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="100" required autocomplete="name" @error('name') aria-invalid="true" @enderror>
                                @error('name') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </label>
                            <label class="pos-field" for="phone"><span>Nomor HP</span>
                                <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone', $user->phone ? \App\Support\Phone::display($user->phone) : '') }}" maxlength="30" placeholder="0812 3456 7890" autocomplete="tel" @error('phone') aria-invalid="true" @enderror>
                                @error('phone') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </label>
                        </div>
                        <div class="pos-form-actions">
                            <button type="submit" class="site-btn site-btn-primary">@svg('heroicon-o-check', ['aria-hidden' => 'true']) Simpan data diri</button>
                        </div>
                    </form>
                </section>

                {{-- Keamanan --}}
                <section class="pos-card">
                    <div class="pos-card-head"><h2>@svg('heroicon-o-shield-check', ['aria-hidden' => 'true']) Keamanan</h2></div>
                    <form method="POST" action="{{ route('cashier.profile.security') }}" class="pos-form" novalidate>
                        @csrf
                        <p class="pos-field-hint">Isi yang ingin diganti saja. Kata sandi baru mengeluarkan kasir web di browser lain & aplikasi kasir; browser ini tetap masuk.</p>
                        <div class="pos-field-row">
                            <label class="pos-field" for="password"><span>Kata sandi baru</span>
                                <input id="password" name="password" type="password" autocomplete="new-password" maxlength="255" @error('password') aria-invalid="true" @enderror>
                                <small class="pos-field-hint">Minimal 8 karakter, huruf & angka</small>
                                @error('password') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </label>
                            <label class="pos-field" for="password_confirmation"><span>Ulangi kata sandi baru</span>
                                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="255">
                            </label>
                        </div>
                        <div class="pos-field-row">
                            <label class="pos-field" for="pin"><span>PIN baru</span>
                                <input id="pin" name="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" @error('pin') aria-invalid="true" @enderror>
                                <small class="pos-field-hint">6 digit untuk kunci layar & approval; bukan angka sama/berurutan</small>
                                @error('pin') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </label>
                            <label class="pos-field" for="current_password"><span>Kata sandi saat ini<span class="site-required" aria-hidden="true">*</span></span>
                                <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="255" required @error('current_password') aria-invalid="true" @enderror>
                                @error('current_password') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </label>
                        </div>
                        <div class="pos-form-actions">
                            <button type="submit" class="site-btn site-btn-primary">@svg('heroicon-o-key', ['aria-hidden' => 'true']) Simpan keamanan</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>

    <script>
        // Foto profil: pratinjau file terpilih, tandai hapus, batalkan pilihan (dikirim saat "Simpan data diri")
        (() => {
            const field = document.querySelector('[data-avatar-field]');
            if (! field) return;
            const input = field.querySelector('[data-avatar-input]');
            const preview = field.querySelector('[data-avatar-preview]');
            const initial = field.querySelector('[data-avatar-initial]');
            const status = field.querySelector('[data-avatar-status]');
            const remove = field.querySelector('[data-avatar-remove]');
            const removeInput = field.querySelector('[data-avatar-remove-input]');
            const cancel = field.querySelector('[data-avatar-cancel]');
            const hint = status.textContent;

            const show = (src) => {
                preview.src = src;
                preview.hidden = src === '';
                initial.hidden = src !== '';
            };
            const reset = () => {
                input.value = '';
                removeInput.value = '0';
                show(preview.dataset.original);
                status.textContent = hint;
                cancel.hidden = true;
                if (remove) remove.hidden = false;
            };

            input.addEventListener('change', () => {
                const file = input.files[0];
                if (! file) return reset();
                if (file.size > 2 * 1024 * 1024) {
                    status.textContent = 'Ukuran foto lebih dari 2 MB. Pilih foto lain.';
                    input.value = '';
                    return;
                }
                removeInput.value = '0';
                show(URL.createObjectURL(file));
                status.textContent = file.name + ' · klik Simpan data diri';
                cancel.hidden = false;
                if (remove) remove.hidden = true;
            });
            remove?.addEventListener('click', () => {
                input.value = '';
                removeInput.value = '1';
                show('');
                status.textContent = 'Foto akan dihapus saat disimpan';
                cancel.hidden = false;
                remove.hidden = true;
            });
            cancel.addEventListener('click', reset);
        })();
    </script>
</x-cashier.layout>
