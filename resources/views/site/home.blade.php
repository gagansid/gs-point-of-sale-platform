{{-- Halaman depan gspos.id (ADR 0008): penjualan sistem + form hubungi sales. Fitur = cakupan MVP di SPEC. --}}
@php
    $features = [
        ['icon' => 'card', 'title' => 'Split payment', 'text' => 'Satu pesanan dibayar tunai, QRIS, transfer, debit, atau kartu kredit sekaligus. Kembalian dihitung otomatis.'],
        ['icon' => 'receipt', 'title' => 'Open bill & opsi menu', 'text' => 'Simpan pesanan meja dan bayar belakangan. Ukuran, gula, topping dengan tambahan harga.'],
        ['icon' => 'cube', 'title' => 'Stok & peringatan', 'text' => 'Stok berkurang otomatis saat terjual, peringatan stok menipis, dan tandai menu habis dalam satu klik.'],
        ['icon' => 'clock', 'title' => 'Shift & kas', 'text' => 'Buka–tutup shift per kasir, kas seharusnya vs aktual, selisih langsung kelihatan.'],
        ['icon' => 'shield', 'title' => 'Void dengan PIN', 'text' => 'Void dan diskon di atas batas butuh PIN supervisor. Tidak bisa menyetujui transaksi sendiri.'],
        ['icon' => 'chart', 'title' => 'Laporan & Excel', 'text' => 'Omzet harian, produk terlaris, dan metode bayar. Export ke Excel kapan saja dari dashboard.'],
    ];
    $roles = ['Owner', 'Manager', 'Supervisor', 'Kasir'];
@endphp

<x-site.layout>
    {{-- ---------- Navigasi ---------- --}}
    <header class="site-header">
        <div class="site-container site-header-inner">
            <a href="{{ route('landing') }}" aria-label="gs.POS beranda"><x-site.logo /></a>

            <nav class="site-nav" aria-label="Menu utama">
                <a href="#fitur">Fitur</a>
                <a href="#cara-kerja">Cara kerja</a>
                <a href="#kontak">Kontak</a>
            </nav>

            <div class="site-header-actions">
                <a href="{{ $loginUrl }}" class="site-btn site-btn-ghost">Masuk</a>
                @if ($signupEnabled)
                    <a href="{{ route('signup') }}" class="site-btn site-btn-primary">Coba gratis</a>
                @else
                    <a href="#kontak" class="site-btn site-btn-primary">Hubungi sales</a>
                @endif
            </div>
        </div>
    </header>

    <main>
        {{-- ---------- Hero ---------- --}}
        <section class="site-hero">
            <div class="site-container site-hero-grid">
                <div>
                    <span class="site-eyebrow">POS untuk kafe, resto & UMKM</span>
                    <h1 class="site-hero-title">Kasir cepat, laporan rapi, bisnis lebih tenang.</h1>
                    <p class="site-hero-text">
                        gs.POS menggabungkan aplikasi kasir di tablet dengan dashboard owner. Pesanan, pembayaran,
                        stok, dan shift tercatat otomatis — Anda cukup fokus melayani pelanggan.
                    </p>
                    <div class="site-hero-actions">
                        @if ($signupEnabled)
                            <a href="{{ route('signup') }}" class="site-btn site-btn-primary site-btn-lg">
                                Coba gratis {{ $trialDays }} hari <x-site.icon name="arrow" class="size-4" />
                            </a>
                            <a href="#kontak" class="site-btn site-btn-outline site-btn-lg">Jadwalkan demo</a>
                        @else
                            <a href="#kontak" class="site-btn site-btn-primary site-btn-lg">
                                Jadwalkan demo <x-site.icon name="arrow" class="size-4" />
                            </a>
                            <a href="{{ $loginUrl }}" class="site-btn site-btn-outline site-btn-lg">Sudah punya akun? Masuk</a>
                        @endif
                    </div>
                    <ul class="site-hero-points">
                        <li><x-site.icon name="check" class="size-4" /> Hak akses sesuai peran</li>
                        <li><x-site.icon name="check" class="size-4" /> Data tiap bisnis terpisah</li>
                        <li><x-site.icon name="check" class="size-4" /> Dibantu setup menu</li>
                    </ul>
                </div>

                {{-- Ilustrasi layar kasir (HTML, bukan gambar) --}}
                <div class="site-mock" aria-hidden="true">
                    <div class="site-mock-bar"><span></span><span></span><span></span></div>
                    <div class="site-mock-body">
                        <div class="site-mock-menu">
                            @foreach ([['Es Kopi Susu', '22.000'], ['Caffe Latte', '26.000'], ['Croissant', '24.000'], ['Matcha Latte', '28.000']] as [$item, $price])
                                <div class="site-mock-tile"><b>{{ $item }}</b><small>Rp{{ $price }}</small></div>
                            @endforeach
                        </div>
                        <div class="site-mock-cart">
                            <div class="site-mock-cart-title">Pesanan #042 · Meja 5</div>
                            <div class="site-mock-line"><span>2× Es Kopi Susu <small>Large</small></span><b>54.000</b></div>
                            <div class="site-mock-line"><span>1× Croissant</span><b>24.000</b></div>
                            <div class="site-mock-total"><span>Total</span><b>Rp78.000</b></div>
                            <div class="site-mock-pay"><span>QRIS</span><span>Tunai</span><span class="is-active">Split</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- Fitur ---------- --}}
        <section id="fitur" class="site-section">
            <div class="site-container">
                <div class="site-section-head">
                    <span class="site-eyebrow">Fitur</span>
                    <h2 class="site-section-title">Semua yang dibutuhkan kasir dan owner</h2>
                    <p class="site-section-text">Dari pesanan pertama sampai tutup shift, tanpa pencatatan manual.</p>
                </div>

                <div class="site-features">
                    @foreach ($features as $feature)
                        <article class="site-card">
                            <span class="site-card-icon"><x-site.icon :name="$feature['icon']" /></span>
                            <h3>{{ $feature['title'] }}</h3>
                            <p>{{ $feature['text'] }}</p>
                        </article>
                    @endforeach
                </div>

                <div class="site-roles">
                    <span class="site-card-icon"><x-site.icon name="users" /></span>
                    <div>
                        <h3>Satu sistem, empat peran</h3>
                        <p>Setiap orang hanya melihat dan melakukan yang menjadi tugasnya.</p>
                    </div>
                    <ul>
                        @foreach ($roles as $role)
                            <li>{{ $role }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        {{-- ---------- Cara kerja ---------- --}}
        <section id="cara-kerja" class="site-section site-section-muted">
            <div class="site-container">
                <div class="site-section-head">
                    <span class="site-eyebrow">Cara kerja</span>
                    <h2 class="site-section-title">Mulai berjualan dalam hitungan hari</h2>
                </div>

                <ol class="site-steps">
                    <li><b>{{ $signupEnabled ? 'Daftar atau hubungi sales' : 'Hubungi tim sales' }}</b><span>{{ $signupEnabled ? 'Coba gratis '.$trialDays.' hari, atau minta demo singkat bersama tim kami.' : 'Ceritakan bisnis Anda. Kami jadwalkan demo singkat.' }}</span></li>
                    <li><b>Setup bersama</b><span>Menu, harga, opsi, dan akun karyawan kami bantu siapkan.</span></li>
                    <li><b>Mulai berjualan</b><span>Kasir memakai aplikasi di tablet, owner memantau dari dashboard.</span></li>
                </ol>
            </div>
        </section>

        {{-- ---------- Hubungi sales ---------- --}}
        <section id="kontak" class="site-section">
            <div class="site-container site-contact">
                <div>
                    <span class="site-eyebrow">Hubungi sales</span>
                    <h2 class="site-section-title">Tertarik memakai gs.POS?</h2>
                    <p class="site-section-text">Isi formulir ini, tim kami menghubungi Anda lewat WhatsApp di hari kerja.</p>
                    <ul class="site-contact-points">
                        <li><x-site.icon name="chat" class="size-5" /> Demo gratis sesuai jenis usaha Anda</li>
                        <li><x-site.icon name="check" class="size-5" /> Dibantu setup menu & akun karyawan</li>
                        <li><x-site.icon name="check" class="size-5" /> Sudah punya akun? <a href="{{ $loginUrl }}">Masuk di sini</a></li>
                    </ul>
                </div>

                <div class="site-form-card">
                    @if (session('contact_sent'))
                        <div class="site-alert site-alert-success" role="status">
                            <b>Terima kasih!</b> Pesan Anda sudah kami terima. Tim sales akan menghubungi Anda segera.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('contact') }}" class="site-form" novalidate>
                        @csrf

                        {{-- Honeypot anti-bot: tersembunyi dari pengguna & pembaca layar --}}
                        <div class="site-hp" aria-hidden="true">
                            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>

                        <div class="site-form-grid">
                            <x-site.field name="name" label="Nama" required autocomplete="name" placeholder="Budi Santoso" />
                            <x-site.field name="business_name" label="Nama bisnis" required placeholder="Kopi Senja" />
                            <x-site.field name="phone" label="Nomor WhatsApp" required type="tel" autocomplete="tel" placeholder="0812 3456 7890" />
                            <x-site.field name="email" label="Email" type="email" autocomplete="email" placeholder="opsional" />
                            <x-site.field name="city" label="Kota" placeholder="Bandung" />
                            <div class="site-field">
                                <label for="business_type">Jenis usaha</label>
                                <select id="business_type" name="business_type">
                                    <option value="">Pilih…</option>
                                    @foreach ($businessTypes as $type)
                                        <option value="{{ $type->value }}" @selected(old('business_type') === $type->value)>{{ $type->getLabel() }}</option>
                                    @endforeach
                                </select>
                                @error('business_type') <p class="site-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="site-field">
                            <label for="message">Pesan <small>(opsional)</small></label>
                            <textarea id="message" name="message" rows="3" maxlength="1000" placeholder="Jumlah kasir, kebutuhan khusus, dll.">{{ old('message') }}</textarea>
                            @error('message') <p class="site-error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Kirim ke tim sales</button>
                        <p class="site-form-note">Data hanya dipakai untuk menghubungi Anda terkait gs.POS.</p>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-container site-footer-inner">
            <x-site.logo light />
            <span>© {{ now()->year }} gs.POS · Abati Technology</span>
            <a href="{{ $loginUrl }}">Masuk</a>
        </div>
    </footer>
</x-site.layout>
