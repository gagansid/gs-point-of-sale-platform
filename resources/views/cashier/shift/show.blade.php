{{-- /cashier/shift: buka shift (kas awal) atau X-report + tutup shift (Q54). --}}
<x-cashier.layout title="Shift" active="shift">
    @if ($shift === null)
        <section class="cashier-card cashier-narrow">
            <h1>Buka shift</h1>
            <p class="cashier-muted">Belum ada shift terbuka di browser ini. Hitung uang di laci, lalu isi kas awal.</p>

            @can('shift.operate')
                <form method="POST" action="{{ route('cashier.shift.store') }}" class="site-form" novalidate>
                    @csrf
                    <div class="site-field">
                        <label for="opening_cash">Kas awal (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                        <input id="opening_cash" name="opening_cash" type="number" min="0" step="any" inputmode="decimal"
                            value="{{ old('opening_cash') }}" required autofocus
                            @error('opening_cash') aria-invalid="true" aria-describedby="opening-error" @enderror>
                        @error('opening_cash') <p id="opening-error" class="site-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Buka shift</button>
                </form>
            @else
                <div class="site-alert site-alert-warning" role="alert">Anda tidak punya izin membuka shift.</div>
            @endcan
        </section>
    @else
        <div class="cashier-grid-2">
            @include('cashier.partials.recap', ['recap' => $recap, 'timezone' => $timezone])

            <section class="cashier-card">
                <h2>{{ $mustForce ? 'Tutup paksa shift' : 'Tutup shift' }}</h2>
                @if ($mustForce)
                    <p class="cashier-muted">Shift ini dibuka {{ $shift->openedBy?->name }}. Menutup shift orang lain dicatat sebagai tutup paksa dan wajib diberi catatan.</p>
                @else
                    <p class="cashier-muted">Hitung uang di laci. Selisih dengan kas seharusnya tercatat di laporan.</p>
                @endif

                @can($mustForce ? 'shift.force_close' : 'shift.operate')
                    <form method="POST" action="{{ route('cashier.shift.close') }}" class="site-form" novalidate
                        data-expected="{{ $recap['cash']['expected_cash'] }}">
                        @csrf
                        <div class="site-field">
                            <label for="actual_cash">Kas aktual (Rp)<span class="site-required" aria-hidden="true">*</span></label>
                            <input id="actual_cash" name="actual_cash" type="number" min="0" step="any" inputmode="decimal"
                                value="{{ old('actual_cash') }}" required
                                @error('actual_cash') aria-invalid="true" aria-describedby="actual-error" @enderror>
                            @error('actual_cash') <p id="actual-error" class="site-error">{{ $message }}</p> @enderror
                            <p class="cashier-muted" data-difference aria-live="polite"></p>
                        </div>
                        <div class="site-field">
                            <label for="note">Catatan @if ($mustForce)<span class="site-required" aria-hidden="true">*</span>@endif</label>
                            <input id="note" name="note" type="text" maxlength="255" value="{{ old('note') }}" @if ($mustForce) required @endif>
                            @error('note') <p class="site-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block"
                            onclick="return confirm('Tutup shift sekarang? Transaksi baru butuh shift baru.')">Tutup shift</button>
                    </form>
                    <script>
                        // Selisih langsung terlihat sebelum menutup (angka final tetap dihitung server)
                        (() => {
                            const form = document.querySelector('[data-expected]');
                            const input = form.querySelector('#actual_cash');
                            const out = form.querySelector('[data-difference]');
                            const fmt = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 });
                            const update = () => {
                                if (input.value === '') { out.textContent = ''; return; }
                                const diff = Number(input.value) - Number(form.dataset.expected);
                                out.textContent = diff === 0 ? 'Pas, tanpa selisih' : `Selisih ${diff > 0 ? '+' : '−'}${fmt.format(Math.abs(diff))}`;
                            };
                            input.addEventListener('input', update);
                            update();
                        })();
                    </script>
                @else
                    <div class="site-alert site-alert-warning" role="alert">Anda tidak punya izin menutup shift ini.</div>
                @endcan
            </section>
        </div>
    @endif
</x-cashier.layout>
