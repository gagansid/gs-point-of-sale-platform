{{-- /cashier/unlock: layar terkunci (ADR 0017). Karyawan di outlet ini pilih nama + PIN → melanjutkan shift yang sama tanpa hitung uang. --}}
<x-cashier.auth title="Layar terkunci">
    <h1 class="cashier-auth-title">Layar terkunci</h1>
    <p class="cashier-auth-sub">{{ $outlet->name }} · shift tetap berjalan. Pilih nama Anda dan masukkan PIN untuk melanjutkan.</p>

    @if ($users->isEmpty())
        <div class="cashier-auth-alert" role="alert">Belum ada karyawan ber-PIN dengan akses kasir web di outlet ini. Masuk dengan akun lain.</div>
    @else
        <form method="POST" action="{{ route('cashier.unlock.store') }}" class="cashier-auth-form" novalidate>
            @csrf
            <div class="cashier-field">
                <label for="user_id">Nama</label>
                <select id="user_id" name="user_id" class="cashier-select" required>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(old('user_id', $current?->id) === $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
                @error('user_id') <p class="cashier-field-error">{{ $message }}</p> @enderror
            </div>
            <div class="cashier-field">
                <label for="pin">PIN</label>
                <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off"
                    placeholder="6 digit" autofocus @error('pin') aria-invalid="true" aria-describedby="pin-error" @enderror>
                @error('pin') <p id="pin-error" class="cashier-field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="cashier-auth-btn">Lanjutkan</button>
        </form>
    @endif

    {{-- Tanpa PIN: masuk dengan username/kata sandi; browser & shift tetap sama --}}
    <form method="POST" action="{{ route('cashier.logout') }}" class="cashier-auth-foot">
        @csrf
        <button type="submit" class="site-link-button">Masuk dengan akun lain</button>
    </form>
</x-cashier.auth>
