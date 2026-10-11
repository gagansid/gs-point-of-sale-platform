{{-- /cashier/outlet: pilih outlet; browser ini menjadi perangkat kasir web di outlet itu (Q54). Lebih dari 3 outlet → dropdown dengan pencarian. --}}
<x-cashier.auth title="Pilih outlet">
    <h1 class="cashier-auth-title">Pilih outlet</h1>
    <p class="cashier-auth-sub">Halo, <strong>{{ $user->name }}</strong>. Browser ini akan menjadi kasir di outlet yang Anda pilih.</p>

    @if (session('status'))
        <div class="cashier-auth-alert" role="status">{{ session('status') }}</div>
    @endif
    @error('outlet_id')
        <div class="cashier-auth-alert" role="alert">{{ $message }}</div>
    @enderror

    @if ($outlets->isEmpty())
        <p class="cashier-auth-sub">Belum ada outlet aktif yang ditugaskan kepada Anda. Hubungi owner.</p>
    @else
        <form method="POST" action="{{ route('cashier.outlet.store') }}">
            @csrf
            @if ($outlets->count() > 3)
                <details class="cashier-select" data-outlet-select>
                    <summary>
                        <span>Pilih outlet</span>
                        <span class="cashier-select-count">{{ $outlets->count() }} outlet</span>
                    </summary>
                    <div class="cashier-select-panel">
                        <input type="search" class="cashier-select-search" placeholder="Cari nama atau kode outlet" aria-label="Cari outlet" autocomplete="off">
                        <div class="cashier-select-list" role="listbox" aria-label="Outlet">
                            @foreach ($outlets as $outlet)
                                <button type="submit" name="outlet_id" value="{{ $outlet->id }}" class="cashier-option" role="option"
                                    data-search="{{ Str::lower($outlet->name.' '.$outlet->code) }}">
                                    <span class="cashier-option-name">{{ $outlet->name }}</span>
                                    <span class="cashier-option-meta">{{ $outlet->code }}</span>
                                </button>
                            @endforeach
                        </div>
                        <p class="cashier-select-empty" hidden>Outlet tidak ditemukan.</p>
                    </div>
                </details>
            @else
                <div class="cashier-option-list">
                    @foreach ($outlets as $outlet)
                        <button type="submit" name="outlet_id" value="{{ $outlet->id }}" class="cashier-option">
                            <span class="cashier-option-name">{{ $outlet->name }}</span>
                            <span class="cashier-option-meta">{{ $outlet->code }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </form>
    @endif

    <form method="POST" action="{{ route('cashier.logout') }}" class="cashier-auth-foot">
        @csrf
        <button type="submit" class="site-link-button">Keluar</button>
    </form>

    <script>
        // Dropdown outlet: fokus ke pencarian saat dibuka, saring berdasarkan nama/kode
        document.querySelectorAll('[data-outlet-select]').forEach((select) => {
            const search = select.querySelector('.cashier-select-search');
            const options = [...select.querySelectorAll('.cashier-option')];
            const empty = select.querySelector('.cashier-select-empty');

            select.addEventListener('toggle', () => select.open && search.focus());
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                options.forEach((option) => { option.hidden = query !== '' && ! option.dataset.search.includes(query); });
                empty.hidden = options.some((option) => ! option.hidden);
            });
            // Enter di kolom cari = pilih hasil pertama
            search.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    options.find((option) => ! option.hidden)?.click();
                }
            });
        });
    </script>
</x-cashier.auth>
