{{-- gspos.id/reset-password/{token}: lupa kata sandi / undangan owner (SPEC Q34). --}}
<x-auth.layout :title="$invitation ? 'Atur kata sandi' : 'Kata sandi baru'" app="Dashboard" :home="route('landing')"
    :heading="$invitation ? 'Selamat datang di gs.POS' : 'Buat kata sandi yang kuat'"
    :text="$invitation ? 'Atur kata sandi untuk mulai mengelola bisnis Anda.' : 'Gunakan kata sandi yang belum pernah dipakai di layanan lain.'"
    :points="[['lock', 'Minimal 8 karakter, huruf dan angka'], ['shield', 'Sesi lama di semua perangkat dikeluarkan'], ['key', 'Link hanya berlaku sekali']]">
    <h1 class="cashier-auth-title">{{ $invitation ? 'Atur kata sandi' : 'Buat kata sandi baru' }}</h1>
    <p class="cashier-auth-sub">{{ $invitation ? 'Satu langkah lagi sebelum masuk ke dashboard.' : 'Sesi lama di semua perangkat akan dikeluarkan.' }}</p>

    <form method="POST" action="{{ route('password.update') }}" class="cashier-auth-form" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="invitation" value="{{ $invitation ? 1 : 0 }}">

        <x-auth.field name="email" label="Alamat email" type="email" icon="mail" :value="$email" required readonly autocomplete="username" />
        <x-auth.field name="password" label="Kata sandi baru" type="password" icon="lock" required autofocus autocomplete="new-password" maxlength="255"
            hint="Minimal 8 karakter, berisi huruf dan angka." />
        <x-auth.field name="password_confirmation" label="Ulangi kata sandi" type="password" icon="lock" required autocomplete="new-password" maxlength="255" />

        <button type="submit" class="cashier-auth-btn">Simpan kata sandi</button>
    </form>
</x-auth.layout>
