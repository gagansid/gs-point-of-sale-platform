@php($months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'])
<x-member.layout title="Profil" active="profile">
    <section class="so-card">
        <div>
            <h1 class="so-page-title">Profil</h1>
            <p class="so-muted">Nomor HP {{ \App\Support\Phone::display($member->phone) }} · kode member <span class="so-mono">{{ $member->code }}</span></p>
        </div>

        <form method="POST" action="{{ route('member.profile.update', ['business' => $business->slug]) }}" class="so-form" novalidate>
            @csrf
            <label class="so-field">
                <span class="so-label">Nama</span>
                <input class="so-input" name="name" type="text" value="{{ old('name', $member->name) }}" maxlength="100" required @error('name') aria-invalid="true" @enderror>
                @error('name') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <label class="so-field">
                <span class="so-label">Email <small>(opsional)</small></span>
                <input class="so-input" name="email" type="email" value="{{ old('email', $member->email) }}" maxlength="150" @error('email') aria-invalid="true" @enderror>
                @error('email') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            @if (\App\Actions\Member\UpdateOwnMemberProfile::birthDateLocked($member))
                {{-- Sudah terisi: hanya kasir/owner yang bisa mengubah (GSPOS-SEC-028) --}}
                <label class="so-field">
                    <span class="so-label">Tanggal lahir</span>
                    <input class="so-input" type="text" value="{{ trim($member->birth_day.' '.$months[$member->birth_month - 1]) }}" disabled>
                    <small class="so-help">Sudah terisi. Minta kasir bila perlu diubah.</small>
                </label>
            @else
                <div class="so-field">
                    <div class="so-row">
                        <label class="so-field">
                            <span class="so-label">Tanggal lahir</span>
                            <select class="so-select" name="birth_day">
                                <option value="">—</option>
                                @foreach (range(1, 31) as $d)
                                    <option value="{{ $d }}" @selected((int) old('birth_day', $member->birth_day) === $d)>{{ $d }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="so-field">
                            <span class="so-label">Bulan</span>
                            <select class="so-select" name="birth_month">
                                <option value="">—</option>
                                @foreach ($months as $i => $m)
                                    <option value="{{ $i + 1 }}" @selected((int) old('birth_month', $member->birth_month) === $i + 1)>{{ $m }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <small class="so-help">Hanya bisa diisi sekali. Voucher ulang tahun dikirim di bulan lahir.</small>
                    @error('birth_day') <em class="so-error">{{ $message }}</em> @enderror
                </div>
            @endif
            <button type="submit" class="so-btn is-primary is-block">Simpan</button>
        </form>
    </section>

    <section class="so-card">
        <h2 class="so-card-title">Keamanan</h2>
        <a class="so-btn is-outline is-block" href="{{ route('member.password.edit', ['business' => $business->slug]) }}">@svg('heroicon-o-key', ['aria-hidden' => 'true']) Ganti kata sandi</a>
    </section>

    <section class="so-card is-danger">
        <div>
            <h2 class="so-card-title">Hapus akun</h2>
            <p class="so-muted">Data pribadi & sisa poin dihapus permanen. Riwayat transaksi tetap ada di toko tanpa nama Anda.</p>
        </div>
        <form method="POST" action="{{ route('member.destroy', ['business' => $business->slug]) }}" class="so-form" novalidate>
            @csrf
            <label class="so-field">
                <span class="so-label">Kata sandi untuk konfirmasi</span>
                <input class="so-input" name="password" type="password" autocomplete="current-password" required @error('password') aria-invalid="true" @enderror>
                @error('password') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <button type="submit" class="so-btn is-danger is-block">@svg('heroicon-o-trash', ['aria-hidden' => 'true']) Hapus akun saya</button>
        </form>
    </section>
</x-member.layout>
