@php($months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'])
<x-member.layout title="Profil" active="profile">
    <section class="member-card">
        <h1 class="member-title">Profil</h1>
        <p class="member-muted">Nomor HP {{ \App\Support\Phone::display($member->phone) }} · kode member {{ $member->code }}</p>

        <form method="POST" action="{{ route('member.profile.update', ['business' => $business->slug]) }}" class="member-form" novalidate>
            @csrf
            <label class="member-field">
                <span>Nama</span>
                <input name="name" type="text" value="{{ old('name', $member->name) }}" maxlength="100" required>
                @error('name') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <label class="member-field">
                <span>Email (opsional)</span>
                <input name="email" type="email" value="{{ old('email', $member->email) }}" maxlength="150">
                @error('email') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            @if (\App\Actions\Member\UpdateOwnMemberProfile::birthDateLocked($member))
                {{-- Sudah terisi: hanya kasir/owner yang bisa mengubah (GSPOS-SEC-028) --}}
                <label class="member-field">
                    <span>Tanggal lahir</span>
                    <input type="text" value="{{ trim($member->birth_day.' '.$months[$member->birth_month - 1]) }}" disabled>
                    <small>Sudah terisi. Minta kasir bila perlu diubah.</small>
                </label>
            @else
                <div class="member-row">
                    <label class="member-field">
                        <span>Tanggal lahir</span>
                        <select name="birth_day">
                            <option value="">—</option>
                            @foreach (range(1, 31) as $d)
                                <option value="{{ $d }}" @selected((int) old('birth_day', $member->birth_day) === $d)>{{ $d }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="member-field">
                        <span>Bulan</span>
                        <select name="birth_month">
                            <option value="">—</option>
                            @foreach ($months as $i => $m)
                                <option value="{{ $i + 1 }}" @selected((int) old('birth_month', $member->birth_month) === $i + 1)>{{ $m }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <small class="member-muted">Hanya bisa diisi sekali. Voucher ulang tahun dikirim di bulan lahir.</small>
            @endif
            @error('birth_day') <p class="member-error">{{ $message }}</p> @enderror
            <button type="submit" class="member-btn">Simpan</button>
        </form>
    </section>

    <section class="member-card">
        <h2 class="member-subtitle">Keamanan</h2>
        <a class="member-btn member-btn-outline" href="{{ route('member.password.edit', ['business' => $business->slug]) }}">Ganti kata sandi</a>
    </section>

    <section class="member-card member-danger">
        <h2 class="member-subtitle">Hapus akun</h2>
        <p class="member-muted">Data pribadi & sisa poin dihapus permanen. Riwayat transaksi tetap ada di toko tanpa nama Anda.</p>
        <form method="POST" action="{{ route('member.destroy', ['business' => $business->slug]) }}" class="member-form" novalidate>
            @csrf
            <label class="member-field">
                <span>Kata sandi untuk konfirmasi</span>
                <input name="password" type="password" autocomplete="current-password" required>
                @error('password') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <button type="submit" class="member-btn member-btn-danger">Hapus akun saya</button>
        </form>
    </section>
</x-member.layout>
