{{--
    Halaman depan gspos.id (ADR 0008): penjualan sistem + form hubungi sales.
    Isi fitur & tabel peran mengikuti SPEC (tabel permission bawaan UserRole). Gaya: resources/css/site-home.css (lp-*).
--}}
@php
    // Daftar modul bergaya struk: [judul grup, [baris...]]
    $modules = [
        ['Penjualan', ['Split payment', 'Open bill & split bill', 'Retur sebagian per item', 'Promo otomatis & voucher', 'Kasbon pelanggan']],
        ['Stok & dapur', ['Stok terpotong saat terjual', 'Resep & paket menu', 'Stok masuk & opname', 'Transfer antar outlet', 'Purchase order ke supplier']],
        ['Pelanggan', ['QR meja, bayar dari HP', 'Antrean sampai diantar', 'Member, poin & tier', 'Kartu stempel']],
        ['Kontrol', ['Shift & selisih kas', 'PIN approval untuk void', 'Hak akses per peran', 'Riwayat perubahan data', 'Laporan Excel & PDF']],
    ];

    // Hak akses bawaan per peran (SPEC "Role & permission"); true = boleh, 'PIN' = dengan PIN approval
    $roles = ['Owner', 'Manager', 'Supervisor', 'Kasir'];
    $access = [
        ['Transaksi, open bill & split payment', [true, true, true, true]],
        ['Diskon di atas batas', [true, true, 'PIN', 'PIN']],
        ['Void & retur', [true, true, true, 'PIN']],
        ['Tutup paksa shift & lihat selisih kas', [true, true, true, false]],
        ['Catat stok masuk', [true, true, true, false]],
        ['Menu, harga & stok opname', [true, true, false, false]],
        ['Laporan', [true, true, '31 hari', 'Shift sendiri']],
        ['Karyawan, outlet & hak akses', [true, false, false, false]],
    ];
@endphp

<x-site.layout>
    {{-- ---------- Navigasi ---------- --}}
    <header class="lp-header">
        <div class="lp-container lp-header-inner">
            <a href="{{ route('landing') }}" aria-label="gs.POS beranda"><x-site.logo /></a>

            <nav class="lp-nav" aria-label="Menu utama">
                <a href="#features">Fitur</a>
                <a href="#roles">Peran</a>
                <a href="#how-it-works">Cara kerja</a>
                <a href="#contact">Kontak</a>
            </nav>

            <div class="lp-header-actions">
                <a href="{{ $loginUrl }}" class="site-btn site-btn-ghost">Masuk</a>
                @if ($signupEnabled)
                    <a href="{{ route('signup') }}" class="site-btn site-btn-primary">Coba gratis</a>
                @else
                    <a href="#contact" class="site-btn site-btn-primary">Hubungi sales</a>
                @endif
            </div>
        </div>
    </header>

    <main>
        {{-- ---------- Hero: latar navy + pola titik (sama dengan login kasir) ---------- --}}
        <section class="lp-hero">
            <div class="lp-container lp-hero-grid">
                <div class="lp-hero-copy">
                    <p class="lp-kicker lp-kicker-light">Kasir & dashboard untuk kafe, resto, UMKM</p>
                    <h1 class="lp-hero-title">Kasir cepat, laporan rapi, <span>bisnis lebih tenang.</span></h1>
                    <p class="lp-hero-text">
                        Aplikasi kasir di tablet, dashboard untuk owner, dan QR di meja untuk pelanggan.
                        Pesanan, pembayaran, stok, dan shift tercatat di satu tempat.
                    </p>
                    <div class="lp-hero-actions">
                        @if ($signupEnabled)
                            <a href="{{ route('signup') }}" class="site-btn lp-btn-light site-btn-lg">
                                Coba gratis {{ $trialDays }} hari <x-site.icon name="arrow" class="size-4" />
                            </a>
                            <a href="#contact" class="site-btn lp-btn-glass site-btn-lg">Jadwalkan demo</a>
                        @else
                            <a href="#contact" class="site-btn lp-btn-light site-btn-lg">
                                Jadwalkan demo <x-site.icon name="arrow" class="size-4" />
                            </a>
                            <a href="{{ $loginUrl }}" class="site-btn lp-btn-glass site-btn-lg">Sudah punya akun? Masuk</a>
                        @endif
                    </div>
                    <p class="lp-hero-note">
                        @if ($signupEnabled)
                            Tanpa kartu kredit · Dibantu setup menu
                        @else
                            Demo gratis · Dibantu setup menu & akun karyawan
                        @endif
                    </p>
                </div>

                {{-- Ilustrasi layar kasir + struk (HTML, bukan gambar) --}}
                <div class="lp-stage" aria-hidden="true">
                    <div class="lp-tablet">
                        <div class="lp-tablet-top">
                            <x-site.logo class="lp-tablet-logo" />
                            <span class="lp-chip lp-chip-ok"><i></i>Shift buka · Rina</span>
                        </div>
                        <div class="lp-tablet-body">
                            <div class="lp-catalog">
                                <div class="lp-cats"><span class="is-active">Kopi</span><span>Non-kopi</span><span>Pastry</span></div>
                                <div class="lp-tiles">
                                    @foreach ([['EK', 'Es Kopi Susu', '22.000'], ['CL', 'Caffe Latte', '26.000'], ['AM', 'Americano', '20.000'], ['ML', 'Matcha Latte', '28.000'], ['CR', 'Croissant', '24.000'], ['BR', 'Brownies', '18.000']] as [$initial, $item, $price])
                                        <div class="lp-tile {{ $loop->first ? 'is-picked' : '' }}">
                                            <span class="lp-tile-img">{{ $initial }}</span>
                                            <b>{{ $item }}</b>
                                            <small>{{ $price }}</small>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="lp-cart">
                                <div class="lp-cart-head"><b>Meja 5</b><small>#042</small></div>
                                <div class="lp-cart-line"><span><em>2</em>Es Kopi Susu<small>Large · less sugar</small></span><b>54.000</b></div>
                                <div class="lp-cart-line"><span><em>1</em>Croissant</span><b>24.000</b></div>
                                <div class="lp-cart-foot">
                                    <div class="lp-cart-total"><span>Total</span><b>78.000</b></div>
                                    <div class="lp-cart-pay">Bayar</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lp-receipt">
                        <div class="lp-receipt-head"><b>KOPI SENJA</b><small>Outlet Dago · Meja 5</small></div>
                        <div class="lp-receipt-row"><span>2× Es Kopi Susu</span><span>54.000</span></div>
                        <div class="lp-receipt-row"><span>1× Croissant</span><span>24.000</span></div>
                        <div class="lp-receipt-row lp-receipt-total"><span>TOTAL</span><span>78.000</span></div>
                        <div class="lp-receipt-row"><span>QRIS</span><span>50.000</span></div>
                        <div class="lp-receipt-row"><span>Tunai</span><span>28.000</span></div>
                        <div class="lp-receipt-paid">LUNAS</div>
                    </div>
                </div>
            </div>

            {{-- Ringkasan angka di kaki hero --}}
            <div class="lp-container">
                <dl class="lp-facts">
                    <div><dt>4 peran</dt><dd>Owner sampai kasir, hak akses bisa diatur</dd></div>
                    <div><dt>Banyak outlet</dt><dd>Menu, stok & laporan per outlet</dd></div>
                    <div><dt>Split payment</dt><dd>Tunai, QRIS, transfer, debit, kartu</dd></div>
                    <div><dt>QR meja</dt><dd>Pelanggan pesan & bayar dari HP</dd></div>
                </dl>
            </div>
        </section>

        {{-- ---------- Tiga layar, satu sistem ---------- --}}
        <section id="features" class="lp-section">
            <div class="lp-container">
                <div class="lp-head">
                    <p class="lp-kicker">Satu sistem, tiga layar</p>
                    <h2 class="lp-title">Setiap orang memegang layar yang sesuai tugasnya</h2>
                </div>

                {{-- Kasir --}}
                <article class="lp-row">
                    <div class="lp-row-copy">
                        <span class="lp-row-tag"><x-site.icon name="tablet" class="size-4" /> Aplikasi kasir</span>
                        <h3>Antrean panjang tetap terlayani</h3>
                        <p>Pilih menu, opsi ukuran & gula, lalu bayar. Satu pesanan boleh dibagi ke beberapa metode bayar, kembalian dihitung otomatis.</p>
                        <ul class="lp-ticks">
                            <li><x-site.icon name="check" class="size-4" /> Open bill untuk pesanan meja, bayar belakangan</li>
                            <li><x-site.icon name="check" class="size-4" /> Void & diskon besar butuh PIN supervisor</li>
                            <li><x-site.icon name="check" class="size-4" /> Tandai menu habis langsung dari layar kasir</li>
                        </ul>
                    </div>
                    <div class="lp-panel" aria-hidden="true">
                        <div class="lp-pay">
                            <div class="lp-pay-head"><span>Pembayaran</span><b>Rp78.000</b></div>
                            <div class="lp-pay-methods">
                                <span class="is-active">QRIS</span><span class="is-active">Tunai</span><span>Transfer</span><span>Debit</span>
                            </div>
                            <div class="lp-pay-line"><span>QRIS</span><b>50.000</b></div>
                            <div class="lp-pay-line"><span>Tunai</span><b>30.000</b></div>
                            <div class="lp-pay-status"><span>Kembalian</span><b>Rp2.000</b></div>
                        </div>
                        <div class="lp-pin">
                            <small>PIN supervisor</small>
                            <div class="lp-pin-dots"><i class="on"></i><i class="on"></i><i class="on"></i><i></i></div>
                            <span>Void #038 · disetujui Dimas</span>
                        </div>
                    </div>
                </article>

                {{-- Owner --}}
                <article class="lp-row lp-row-flip">
                    <div class="lp-row-copy">
                        <span class="lp-row-tag"><x-site.icon name="desktop" class="size-4" /> Dashboard owner</span>
                        <h3>Pantau semua outlet tanpa harus datang</h3>
                        <p>Omzet, produk terlaris, metode bayar, dan selisih kas tiap shift. Export ke Excel atau PDF kapan saja.</p>
                        <ul class="lp-ticks">
                            <li><x-site.icon name="check" class="size-4" /> Stok berkurang otomatis, peringatan saat menipis</li>
                            <li><x-site.icon name="check" class="size-4" /> Kas seharusnya vs aktual di setiap tutup shift</li>
                            <li><x-site.icon name="check" class="size-4" /> Riwayat perubahan harga & data penting</li>
                        </ul>
                    </div>
                    <div class="lp-panel" aria-hidden="true">
                        <div class="lp-dash">
                            <div class="lp-kpis">
                                <div><small>Omzet hari ini</small><b>4.820.000</b><em class="up">+12%</em></div>
                                <div><small>Transaksi</small><b>186</b><em class="up">+9</em></div>
                                <div><small>Selisih kas</small><b>0</b><em>Sesuai</em></div>
                            </div>
                            <div class="lp-chart">
                                <div class="lp-chart-head"><b>Omzet 7 hari</b><small>Semua outlet</small></div>
                                <div class="lp-bars">
                                    @foreach ([['Sen', 52], ['Sel', 61], ['Rab', 48], ['Kam', 70], ['Jum', 84], ['Sab', 100], ['Min', 91]] as [$day, $height])
                                        <span style="--h: {{ $height }}%" @class(['is-today' => $loop->last])><i></i><small>{{ $day }}</small></span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                {{-- Pelanggan --}}
                <article class="lp-row">
                    <div class="lp-row-copy">
                        <span class="lp-row-tag"><x-site.icon name="qr" class="size-4" /> QR di meja</span>
                        <h3>Pelanggan pesan sendiri, kasir tidak kewalahan</h3>
                        <p>Scan QR di meja, pilih menu, bayar dari HP. Pesanan masuk antrean dan pelanggan bisa melihat statusnya sampai diantar.</p>
                        <ul class="lp-ticks">
                            <li><x-site.icon name="check" class="size-4" /> Member mengumpulkan poin & stempel</li>
                            <li><x-site.icon name="check" class="size-4" /> Promo otomatis & voucher langsung terpakai</li>
                            <li><x-site.icon name="check" class="size-4" /> Antrean: disiapkan, siap diantar, diantar</li>
                        </ul>
                    </div>
                    <div class="lp-panel lp-panel-phone" aria-hidden="true">
                        <div class="lp-phone">
                            <div class="lp-phone-head"><small>Kopi Senja · Dago</small><b>Meja 5</b></div>
                            <div class="lp-track">
                                <span class="done">Dibayar</span><span class="now">Disiapkan</span><span>Diantar</span>
                            </div>
                            @foreach ([['Es Kopi Susu', 'Large · less sugar', '2', '54.000'], ['Croissant', 'Butter', '1', '24.000']] as [$item, $note, $qty, $price])
                                <div class="lp-phone-item">
                                    <span class="lp-tile-img">{{ $qty }}×</span>
                                    <div><b>{{ $item }}</b><small>{{ $note }}</small></div>
                                    <b>{{ $price }}</b>
                                </div>
                            @endforeach
                            <div class="lp-phone-foot"><span>+15 poin member</span><b>Rp78.000</b></div>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        {{-- ---------- Daftar modul bergaya struk ---------- --}}
        <section class="lp-section lp-section-muted">
            <div class="lp-container">
                <div class="lp-head lp-head-split">
                    <div>
                        <p class="lp-kicker">Semua dalam satu langganan</p>
                        <h2 class="lp-title">Fitur yang biasanya butuh tiga aplikasi</h2>
                    </div>
                    <p class="lp-lead">Tidak perlu menyambung aplikasi kasir, buku stok, dan spreadsheet laporan. Semua modul saling terhubung dan membaca data yang sama.</p>
                </div>

                <div class="lp-ledger">
                    @foreach ($modules as [$group, $items])
                        <div class="lp-ledger-col">
                            <h3><span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>{{ $group }}</h3>
                            <ul>
                                @foreach ($items as $item)
                                    <li><span>{{ $item }}</span><x-site.icon name="check" class="size-4" /></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ---------- Peran & hak akses ---------- --}}
        <section id="roles" class="lp-section">
            <div class="lp-container lp-roles">
                <div class="lp-roles-copy">
                    <p class="lp-kicker">Peran & hak akses</p>
                    <h2 class="lp-title">Kasir tidak bisa menyetujui transaksinya sendiri</h2>
                    <p class="lp-lead">Setiap orang hanya melihat dan melakukan yang menjadi tugasnya. Aksi berisiko meminta PIN atasan, dan pemberi PIN tercatat di setiap transaksi.</p>
                    <p class="lp-roles-note">Tabel ini pengaturan bawaan. Owner bisa mengubahnya per bisnis, bahkan per karyawan.</p>
                </div>

                <div class="lp-matrix-wrap">
                    <table class="lp-matrix">
                        <thead>
                            <tr>
                                <th scope="col"><span class="sr-only">Aksi</span></th>
                                @foreach ($roles as $role)
                                    <th scope="col">{{ $role }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($access as [$action, $values])
                                <tr>
                                    <th scope="row">{{ $action }}</th>
                                    @foreach ($values as $value)
                                        <td>
                                            @if ($value === true)
                                                <x-site.icon name="check" class="lp-yes" /><span class="sr-only">Boleh</span>
                                            @elseif ($value === false)
                                                <span class="lp-no" aria-hidden="true"></span><span class="sr-only">Tidak</span>
                                            @else
                                                <span @class(['lp-tag', 'lp-tag-pin' => $value === 'PIN'])>{{ $value }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ---------- Cara kerja ---------- --}}
        <section id="how-it-works" class="lp-section lp-section-muted">
            <div class="lp-container">
                <div class="lp-head">
                    <p class="lp-kicker">Cara kerja</p>
                    <h2 class="lp-title">Mulai berjualan dalam hitungan hari</h2>
                </div>

                <ol class="lp-steps">
                    <li>
                        <b>{{ $signupEnabled ? 'Daftar atau hubungi sales' : 'Hubungi tim sales' }}</b>
                        <span>{{ $signupEnabled ? 'Coba gratis '.$trialDays.' hari, atau minta demo singkat bersama tim kami.' : 'Ceritakan bisnis Anda. Kami jadwalkan demo singkat.' }}</span>
                    </li>
                    <li>
                        <b>Setup bersama</b>
                        <span>Menu, harga, opsi, metode bayar, dan akun karyawan kami bantu siapkan.</span>
                    </li>
                    <li>
                        <b>Mulai berjualan</b>
                        <span>Kasir memakai aplikasi di tablet, owner memantau dari dashboard.</span>
                    </li>
                </ol>
            </div>
        </section>

        {{-- ---------- Hubungi sales ---------- --}}
        <section id="contact" class="lp-section">
            <div class="lp-container">
                <div class="lp-contact">
                    <div class="lp-contact-brand">
                        <p class="lp-kicker lp-kicker-light">Hubungi sales</p>
                        <h2>Tertarik memakai gs.POS?</h2>
                        <p>Isi formulir ini, tim kami menghubungi Anda lewat WhatsApp di hari kerja.</p>
                        <ul>
                            <li><x-site.icon name="chat" /> Demo gratis sesuai jenis usaha Anda</li>
                            <li><x-site.icon name="store" /> Dibantu setup menu & akun karyawan</li>
                            <li><x-site.icon name="user" /> Sudah punya akun? <a href="{{ $loginUrl }}">Masuk di sini</a></li>
                        </ul>
                    </div>

                    <div class="lp-contact-form">
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
            </div>
        </section>
    </main>

    <footer class="lp-footer">
        <div class="lp-container">
            <div class="lp-footer-grid">
                <div class="lp-footer-brand">
                    <x-site.logo light />
                    <p>Aplikasi kasir dan dashboard untuk kafe, resto, dan UMKM di Indonesia.</p>
                </div>
                <nav aria-label="Produk">
                    <h3>Produk</h3>
                    <a href="#features">Fitur</a>
                    <a href="#roles">Peran & hak akses</a>
                    <a href="#how-it-works">Cara kerja</a>
                </nav>
                <nav aria-label="Akun">
                    <h3>Akun</h3>
                    <a href="{{ $loginUrl }}">Masuk</a>
                    @if ($signupEnabled)
                        <a href="{{ route('signup') }}">Coba gratis</a>
                    @endif
                    <a href="#contact">Hubungi sales</a>
                </nav>
            </div>
            <div class="lp-footer-bottom">
                <span>© {{ now()->year }} gs.POS · Abati Technology</span>
            </div>
        </div>
    </footer>
</x-site.layout>
