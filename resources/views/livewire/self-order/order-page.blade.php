{{--
    Halaman pesan dari meja (ADR 0014, tampilan ADR 0027 §6): Menu → Keranjang → Pesananku lewat navigasi bawah.
    Menu: cari, tab kategori menempel + scroll-spy, mode Kotak/Baris (localStorage), + cepat untuk produk tanpa opsi.
    Harga di layar = pratinjau; tagihan final dihitung server. Semua teks pengguna di-escape.
--}}
@php
    $money = fn (string $v): string => \App\Support\Money::format($v);
    $quote = $this->quote;
    $count = $this->cartQty();
    $member = $this->member();
    $orderCount = $this->myOrders->count();
    $categoryNames = $this->catalog['categories']->pluck('name', 'id')->all();
    $setting = $context->setting;
    $hours = $setting->opens_at && $setting->closes_at ? substr($setting->opens_at, 0, 5).'–'.substr($setting->closes_at, 0, 5) : null;
@endphp
<div x-data="{
        setLayout(value) { document.documentElement.dataset.soView = value; try { localStorage.setItem('gspos.so.view', value) } catch (e) {} },
        toast: null,
        toastTimer: null,
        showToast(detail) { this.toast = detail; clearTimeout(this.toastTimer); this.toastTimer = setTimeout(() => this.toast = null, 5000) },
    }"
    x-on:so-toast.window="showToast($event.detail)">
    <main @class(['so-main', 'has-cartbar' => in_array($view, ['menu', 'cart'], true) && $count > 0])>
        @if ($error)
            <div class="so-alert is-danger" role="alert">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true']) <span>{{ $error }}</span></div>
        @endif

        @if ($view === 'menu')
            @php($sections = $this->menuSections())
            @php($plainQty = $this->plainQuantities())

            @if ($setting->welcome_text || $hours)
                <div class="so-welcome">
                    @if ($setting->welcome_text)
                        <p class="so-secondary">{{ $setting->welcome_text }}</p>
                    @endif
                    @if ($hours)
                        <span class="so-badge is-success">@svg('heroicon-o-clock', ['aria-hidden' => 'true']) Buka {{ $hours }}</span>
                    @endif
                </div>
            @endif

            <div class="so-toolbar">
                <label class="so-input-icon">
                    @svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true'])
                    <input class="so-input" type="search" wire:model.live.debounce.300ms="search" maxlength="50" placeholder="Cari menu" aria-label="Cari menu" enterkeyhint="search" autocomplete="off">
                </label>
                <div class="so-segment" role="group" aria-label="Tampilan menu">
                    <button type="button" data-view="grid" x-on:click="setLayout('grid')" aria-label="Tampilan kotak">@svg('heroicon-o-squares-2x2', ['aria-hidden' => 'true'])</button>
                    <button type="button" data-view="list" x-on:click="setLayout('list')" aria-label="Tampilan baris">@svg('heroicon-o-bars-3', ['aria-hidden' => 'true'])</button>
                </div>
            </div>

            <div class="so-menu-wrap" wire:loading.class="is-loading" wire:target="search"
                x-data="{
                    active: @js($sections[0]['id'] ?? ''),
                    go(id) {
                        this.active = id;
                        document.getElementById('sec-' + id)?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                    },
                }"
                x-init="$watch('active', (id) => { const tab = document.getElementById('tab-' + id); if (tab) tab.parentElement.scrollTo({ left: tab.offsetLeft - 16, behavior: 'smooth' }) })">
                @if ($search === '' && count($sections) > 1)
                    <nav class="so-cats" aria-label="Kategori menu">
                        <div class="so-cats-scroll">
                            @foreach ($sections as $section)
                                <button type="button" id="tab-{{ $section['id'] }}" wire:key="tab-{{ $section['id'] }}" class="so-tab"
                                    :class="active === @js($section['id']) && 'is-active'" :aria-current="active === @js($section['id']) ? 'true' : 'false'"
                                    x-on:click="go(@js($section['id']))">{{ $section['name'] }}</button>
                            @endforeach
                        </div>
                    </nav>
                @endif

                @forelse ($sections as $section)
                    <section class="so-menu-section" id="sec-{{ $section['id'] }}" wire:key="sec-{{ $section['id'] }}" aria-labelledby="sec-title-{{ $section['id'] }}"
                        x-intersect:enter.margin.-120px.0px.-60%.0px="active = @js($section['id'])">
                        <h2 class="so-menu-title" id="sec-title-{{ $section['id'] }}">{{ $section['name'] }} <span class="so-muted">{{ $section['products']->count() }}</span></h2>

                        @if ($section['products']->isEmpty())
                            <div class="so-card">
                                <div class="so-empty">
                                    <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-magnifying-glass')</span>
                                    <p class="so-empty-title">Menu tidak ditemukan</p>
                                    <p>Coba kata lain, atau lihat semua menu.</p>
                                    <div class="so-actions"><button type="button" class="so-btn is-outline" wire:click="$set('search', '')">Lihat semua menu</button></div>
                                </div>
                            </div>
                        @else
                            <ul class="so-menu">
                                @foreach ($section['products'] as $product)
                                    @php($quick = \App\Livewire\SelfOrder\OrderPage::isQuickAdd($product))
                                    @php($inCart = $plainQty[$product->id] ?? 0)
                                    <li wire:key="p-{{ $section['id'] }}-{{ $product->id }}">
                                        <article @class(['so-product', 'is-unavailable' => ! $product->is_available])>
                                            <span class="so-product-media">
                                                @if ($product->imageUrl())
                                                    <img src="{{ $product->imageUrl() }}" alt="" loading="lazy" width="160" height="160">
                                                @else
                                                    @svg('heroicon-o-photo', ['aria-hidden' => 'true'])
                                                @endif
                                            </span>
                                            <span class="so-product-body">
                                                <span class="so-product-name">{{ $product->name }}</span>
                                                @if ($categoryNames[$product->category_id] ?? null)
                                                    <span class="so-product-desc">{{ $categoryNames[$product->category_id] }}</span>
                                                @endif
                                            </span>
                                            <span class="so-product-foot">
                                                <span class="so-product-price">{{ $money($product->price) }}</span>
                                                @if (! $product->is_available)
                                                    <span class="so-badge is-danger is-soldout">Habis</span>
                                                @elseif ($quick && $inCart > 0)
                                                    <span class="so-qty is-sm">
                                                        <button type="button" wire:click="decrementProduct('{{ $product->id }}')" aria-label="{{ $inCart > 1 ? 'Kurangi' : 'Hapus' }} {{ $product->name }}">@svg($inCart > 1 ? 'heroicon-o-minus' : 'heroicon-o-trash', ['aria-hidden' => 'true'])</button>
                                                        <span aria-live="polite">{{ $inCart }}</span>
                                                        <button type="button" wire:click="quickAdd('{{ $product->id }}')" @disabled($count >= \App\Actions\SelfOrder\SubmitSelfOrder::MAX_ITEMS) aria-label="Tambah {{ $product->name }}">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                                                    </span>
                                                @else
                                                    <button type="button" class="so-icon-btn is-sm is-primary" wire:click="quickAdd('{{ $product->id }}')" aria-label="Tambah {{ $product->name }}">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                                                @endif
                                            </span>
                                            <button type="button" class="so-product-open" wire:click="addProduct('{{ $product->id }}')" @disabled(! $product->is_available)
                                                aria-label="{{ $product->is_available ? $product->name.', '.$money($product->price).', lihat pilihan' : $product->name.' habis' }}"></button>
                                        </article>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @empty
                    <div class="so-card">
                        <div class="so-empty">
                            <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-book-open')</span>
                            <p class="so-empty-title">Menu belum tersedia</p>
                            <p>Silakan pesan langsung di kasir.</p>
                        </div>
                    </div>
                @endforelse
            </div>
        @elseif ($view === 'cart')
            @if ($cart === [])
                <section class="so-card">
                    <div class="so-empty">
                        <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-shopping-bag')</span>
                        <p class="so-empty-title">Keranjang masih kosong</p>
                        <p>Pilih menu dulu, lalu kembali ke sini untuk membayar.</p>
                        <div class="so-actions"><button type="button" class="so-btn is-primary" wire:click="show('menu')">Lihat menu</button></div>
                    </div>
                </section>
            @else
                {{-- Daftar pesanan ringkas: ketuk baris = ubah, stepper kecil di kanan --}}
                <section class="so-card is-tight">
                    <div class="so-card-head">
                        <h1 class="so-card-title">Pesanan <span class="so-muted so-num">· {{ $count }} item</span></h1>
                        <button type="button" class="so-btn is-ghost is-sm" wire:click="show('menu')">@svg('heroicon-o-plus', ['aria-hidden' => 'true']) Tambah</button>
                    </div>
                    <ul class="so-cart">
                        @foreach ($cart as $i => $line)
                            <li class="so-cart-row" wire:key="l-{{ $i }}-{{ $line['product_id'] }}">
                                <button type="button" class="so-cart-main" wire:click="editLine({{ $i }})" aria-label="Ubah {{ $line['name'] }}">
                                    <span class="so-cart-name">{{ $line['name'] }}</span>
                                    @if ($line['option_names'] !== [] || $line['notes'])
                                        <span class="so-cart-meta">{{ implode(', ', $line['option_names']) }}@if ($line['option_names'] !== [] && $line['notes']) · @endif @if ($line['notes'])<i>“{{ $line['notes'] }}”</i>@endif</span>
                                    @endif
                                    <span class="so-cart-edit">Ubah</span>
                                </button>
                                <div class="so-cart-side">
                                    <span class="so-cart-price">{{ $money($this->linePrice($line)) }}</span>
                                    <span class="so-qty is-sm">
                                        <button type="button" wire:click="decrement({{ $i }})" aria-label="{{ $line['qty'] > 1 ? 'Kurangi' : 'Hapus' }} {{ $line['name'] }}">@svg($line['qty'] > 1 ? 'heroicon-o-minus' : 'heroicon-o-trash', ['aria-hidden' => 'true'])</button>
                                        <span aria-live="polite">{{ $line['qty'] }}</span>
                                        <button type="button" wire:click="increment({{ $i }})" @disabled($count >= \App\Actions\SelfOrder\SubmitSelfOrder::MAX_ITEMS) aria-label="Tambah {{ $line['name'] }}">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Catatan & voucher: baris yang bisa dibuka, tidak memakan tempat bila kosong --}}
                <section class="so-card is-tight is-rows">
                    <div class="so-extra" x-data="{ open: @js($notes !== '') }">
                        <button type="button" class="so-extra-row" x-on:click="open = ! open" :aria-expanded="open">
                            @svg('heroicon-o-chat-bubble-left-ellipsis', ['aria-hidden' => 'true'])
                            <span class="so-extra-label">Catatan untuk outlet</span>
                            <span class="so-extra-value">{{ $notes !== '' ? \Illuminate\Support\Str::limit($notes, 24) : 'Tambah' }}</span>
                            @svg('heroicon-o-chevron-down', ['class' => 'so-extra-caret', ':class' => "open && 'is-open'", 'aria-hidden' => 'true'])
                        </button>
                        <div class="so-extra-body" x-show="open" x-cloak>
                            <input class="so-input" type="text" wire:model.blur="notes" maxlength="255" placeholder="mis. tanpa sedotan, minta sendok" aria-label="Catatan untuk outlet">
                        </div>
                    </div>
                    <div class="so-extra" x-data="{ open: @js($voucherCode !== '' || (bool) $quote['error']) }">
                        <button type="button" class="so-extra-row" x-on:click="open = ! open" :aria-expanded="open">
                            @svg('heroicon-o-ticket', ['aria-hidden' => 'true'])
                            <span class="so-extra-label">Voucher</span>
                            @if ($checkedVoucher !== '' && $quote['totals'] && \App\Support\Money::isPositive($quote['totals']->voucherDiscount))
                                <span class="so-extra-value so-success">{{ $checkedVoucher }} · −{{ $money($quote['totals']->voucherDiscount) }}</span>
                            @else
                                <span class="so-extra-value">{{ $voucherCode !== '' ? $voucherCode : 'Pakai kode' }}</span>
                            @endif
                            @svg('heroicon-o-chevron-down', ['class' => 'so-extra-caret', ':class' => "open && 'is-open'", 'aria-hidden' => 'true'])
                        </button>
                        <div class="so-extra-body" x-show="open" x-cloak>
                            <div class="so-inline">
                                <input class="so-input" type="text" wire:model.blur="voucherCode" maxlength="30" autocomplete="off" autocapitalize="characters" placeholder="Kode voucher" aria-label="Kode voucher"
                                    @if ($quote['error']) aria-invalid="true" aria-describedby="voucher-error" @endif>
                                <button type="button" class="so-btn is-outline" wire:click="$refresh">Pakai</button>
                            </div>
                            @if ($quote['error'])
                                <em class="so-error" id="voucher-error" role="alert">{{ $quote['error'] }}</em>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="so-card is-tight">
                    <h2 class="so-card-title">Ringkasan</h2>
                    @if ($quote['totals'])
                        @php($t = $quote['totals'])
                        <dl class="so-totals is-compact">
                            <dt>Subtotal</dt><dd>{{ $money($t->subtotal) }}</dd>
                            @if (\App\Support\Money::isPositive($t->voucherDiscount))
                                <dt>Voucher</dt><dd class="is-discount">−{{ $money($t->voucherDiscount) }}</dd>
                            @endif
                            @if (\App\Support\Money::isPositive($t->memberDiscount))
                                <dt>Diskon member</dt><dd class="is-discount">−{{ $money($t->memberDiscount) }}</dd>
                            @endif
                            @if (\App\Support\Money::isPositive($t->serviceTotal))
                                <dt>Service</dt><dd>{{ $money($t->serviceTotal) }}</dd>
                            @endif
                            @if (\App\Support\Money::isPositive($t->taxTotal))
                                <dt>Pajak</dt><dd>{{ $money($t->taxTotal) }}</dd>
                            @endif
                            <dt class="is-grand">Total</dt><dd class="is-grand">{{ $money($t->grandTotal) }}</dd>
                        </dl>
                    @else
                        <p class="so-muted">Ringkasan muncul setelah kode voucher diperbaiki atau dihapus.</p>
                    @endif
                </section>

                {{-- Siapa yang memesan: member (wajib bila program aktif, ADR 0027 K1) atau nama tamu --}}
                @if ($member)
                    <section class="so-card is-tight is-row">
                        <span class="so-member-chip-avatar is-lg" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($member->name), 0, 1)) }}</span>
                        <div class="so-list-main">
                            <strong>{{ $member->name }}</strong>
                            <span class="so-muted">Poin & diskon member ikut dihitung</span>
                        </div>
                    </section>
                @elseif ($this->needsLogin())
                    <section class="so-card is-tight is-row is-info">
                        <span class="so-member-chip-avatar is-lg" aria-hidden="true">@svg('heroicon-o-user')</span>
                        <div class="so-list-main">
                            <strong>Masuk untuk membayar</strong>
                            <span class="so-muted">Keranjang tetap tersimpan. Belum punya akun? <a href="{{ route('self-order.register', ['token' => $context->table->qr_token, 'from' => 'cart']) }}">Daftar gratis</a></span>
                        </div>
                    </section>
                @else
                    <section class="so-card is-tight">
                        <label class="so-field">
                            <span class="so-label">Nama pemesan</span>
                            <div class="so-input-icon">
                                @svg('heroicon-o-user', ['aria-hidden' => 'true'])
                                <input class="so-input" type="text" wire:model="customerName" maxlength="100" autocomplete="name" placeholder="Nama untuk dipanggil">
                            </div>
                        </label>
                    </section>
                @endif
                <p class="so-muted so-foot">Kasir memeriksa pesanan dulu, setelah itu kamu bisa membayar.</p>
            @endif
        @else
            <section class="so-orders" wire:poll.5s aria-live="polite">
                @forelse ($this->myOrders as $selfOrder)
                    @php($status = $statusLabel($selfOrder))
                    <article class="so-card" wire:key="s-{{ $selfOrder->id }}">
                        <div class="so-card-head">
                            <h2 class="so-card-title">{{ $selfOrder->order?->order_number ?? 'Pesanan '.$selfOrder->created_at->setTimezone($context->outlet->timezone)->format('H.i') }}</h2>
                            <span class="so-badge is-{{ $status['tone'] === 'muted' ? 'gray' : $status['tone'] }}">{{ $status['label'] }}</span>
                        </div>
                        <p class="so-secondary so-num">{{ $selfOrder->itemCount() }} item · {{ $money($selfOrder->order->grand_total ?? $selfOrder->estimated_total) }}@if ($selfOrder->order && \App\Support\Money::isPositive($selfOrder->order->paid_total) && $selfOrder->order->status === \App\Enums\OrderStatus::AwaitingPayment) · sisa {{ $money(\App\Support\Money::sub($selfOrder->order->grand_total, $selfOrder->order->paid_total)) }}@endif</p>
                        @if ($status['note'] !== '')
                            <p class="so-muted">{{ $status['note'] }}</p>
                        @endif
                        @if ($selfOrder->order?->status === \App\Enums\OrderStatus::AwaitingPayment)
                            <div class="so-actions is-stack">
                                @if (($points = $this->pointsFor($selfOrder)) > 0)
                                    <button type="button" class="so-btn is-outline is-block" wire:click="payWithPoints('{{ $selfOrder->id }}')" wire:loading.attr="disabled">
                                        Pakai {{ number_format($points, 0, ',', '.') }} poin ({{ $money(\App\Support\Money::mul((string) \App\Models\MemberSetting::current()->redeem_value, $points)) }})
                                    </button>
                                @endif
                                @if ($this->onlineReady)
                                    <button type="button" class="so-btn is-primary is-block" wire:click="payOnline('{{ $selfOrder->id }}')" wire:loading.attr="disabled">Bayar online (QRIS / e-wallet)</button>
                                @endif
                                @if ($context->setting->allow_pay_at_cashier || ! $this->onlineReady)
                                    <p class="so-muted">{{ $this->onlineReady ? 'Atau bayar' : 'Bayar' }} di kasir dengan menyebut nomor pesanan.</p>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="so-card">
                        <div class="so-empty">
                            <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-clipboard-document-list')</span>
                            <p class="so-empty-title">Belum ada pesanan</p>
                            <p>Pesanan dari HP ini akan muncul di sini beserta statusnya.</p>
                            <div class="so-actions"><button type="button" class="so-btn is-primary" wire:click="show('menu')">Lihat menu</button></div>
                        </div>
                    </div>
                @endforelse
            </section>
        @endif
    </main>

    {{-- Bilah aksi di atas navigasi bawah: Menu → Lihat keranjang; Keranjang → Masuk / Kirim --}}
    @if ($view === 'cart' && $count > 0)
        <div class="so-cartbar">
            <div class="so-cartbar-inner">
                <div class="so-cartbar-info">
                    <span class="so-muted">Total</span>
                    <strong>{{ $quote['totals'] ? $money($quote['totals']->grandTotal) : '—' }}</strong>
                </div>
                @if ($this->needsLogin())
                    <a class="so-btn is-primary" href="{{ route('self-order.login', ['token' => $context->table->qr_token, 'from' => 'cart']) }}">@svg('heroicon-o-user-circle', ['aria-hidden' => 'true']) Masuk untuk bayar</a>
                @else
                    <button type="button" class="so-btn is-primary" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" @disabled($quote['totals'] === null)>Kirim pesanan @svg('heroicon-o-arrow-right', ['aria-hidden' => 'true'])</button>
                @endif
            </div>
        </div>
    @endif

    @if ($view === 'menu' && $count > 0)
        <div class="so-cartbar">
            <div class="so-cartbar-inner">
                <div class="so-cartbar-info">
                    <span class="so-muted so-num">{{ $count }} item</span>
                    <strong>{{ $quote['totals'] ? $money($quote['totals']->grandTotal) : '—' }}</strong>
                </div>
                <button type="button" class="so-btn is-primary" wire:click="show('cart')">Lihat keranjang @svg('heroicon-o-arrow-right', ['aria-hidden' => 'true'])</button>
            </div>
        </div>
    @endif

    {{-- Toast (Urungkan hapus item) --}}
    <div class="so-toast-wrap" aria-live="polite">
        <template x-if="toast">
            <div class="so-toast" role="status">
                <span x-text="toast.message"></span>
                <button type="button" class="so-btn is-ghost" x-show="toast.undo" x-on:click="$wire.undoRemove(); toast = null">Urungkan</button>
            </div>
        </template>
    </div>

    <nav class="so-tabbar" aria-label="Langkah pesanan">
        <div class="so-tabbar-inner">
            @foreach (['menu' => ['Menu', 'heroicon-o-book-open', 0], 'cart' => ['Keranjang', 'heroicon-o-shopping-bag', $count], 'status' => ['Pesananku', 'heroicon-o-clipboard-document-list', $orderCount]] as $key => [$label, $icon, $badge])
                <button type="button" wire:click="show('{{ $key }}')" @class(['is-active' => $view === $key]) @if ($view === $key) aria-current="page" @endif>
                    <span class="so-tabbar-icon">
                        @svg($icon, ['aria-hidden' => 'true'])
                        @if ($badge > 0)
                            <span class="so-tabbar-count">{{ $badge > 99 ? '99+' : $badge }}</span>
                        @endif
                    </span>
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </nav>

    {{-- Dialog opsi (bottom sheet): pegangan geser, fokus terkunci, Esc/✕/latar untuk menutup --}}
    @if ($pickProductId)
        @php($pickProduct = $this->catalog['products']->firstWhere('id', $pickProductId))
        <div class="so-sheet" role="dialog" aria-modal="true" aria-labelledby="pick-title" wire:key="sheet-{{ $pickProductId }}-{{ $pickIndex ?? 'new' }}"
            x-data="{ startY: null, dy: 0 }" x-trap.noscroll="true" x-on:keydown.escape="$wire.closePicker()">
            <button type="button" class="so-sheet-backdrop" wire:click="closePicker" aria-label="Tutup" tabindex="-1"></button>
            <form class="so-sheet-panel" wire:submit="savePick" :style="dy > 0 && `transform: translateY(${dy}px); transition: none`">
                <div class="so-sheet-drag"
                    x-on:touchstart.passive="startY = $event.touches[0].clientY"
                    x-on:touchmove.passive="if (startY !== null) dy = Math.max(0, $event.touches[0].clientY - startY)"
                    x-on:touchend="if (dy > 90) { $wire.closePicker() } else { dy = 0 } startY = null">
                    <span class="so-sheet-grip" aria-hidden="true"></span>
                    <div class="so-sheet-head">
                        <div class="so-sheet-hero">
                            <span class="so-product-media">
                                @if ($pickProduct?->imageUrl())
                                    <img src="{{ $pickProduct->imageUrl() }}" alt="" width="72" height="72">
                                @else
                                    @svg('heroicon-o-photo', ['aria-hidden' => 'true'])
                                @endif
                            </span>
                            <div>
                                <h2 id="pick-title" class="so-card-title">{{ $pickProduct?->name }}</h2>
                                <p class="so-secondary so-num">{{ $pickProduct ? $money($pickProduct->price) : '' }}</p>
                                @if ($pickProduct && ($categoryNames[$pickProduct->category_id] ?? null))
                                    <p class="so-muted">{{ $categoryNames[$pickProduct->category_id] }}</p>
                                @endif
                            </div>
                        </div>
                        <button type="button" class="so-icon-btn is-plain" wire:click="closePicker" aria-label="Tutup">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
                    </div>
                </div>

                <div class="so-sheet-body">
                    @foreach ($this->pickerGroups() as $group)
                        <fieldset class="so-options" wire:key="g-{{ $group->id }}" @if ($pickErrorGroup === $group->id) aria-describedby="g-error-{{ $group->id }}" @endif>
                            <legend>
                                <span>{{ $group->name }}</span>
                                <span @class(['so-badge', 'is-warning' => $pickErrorGroup === $group->id])>{{ $group->min_select > 0 ? 'Pilih '.($group->min_select === $group->max_select ? $group->min_select : $group->min_select.'–'.$group->max_select) : 'Opsional'.($group->max_select > 1 ? ', maks. '.$group->max_select : '') }}</span>
                            </legend>
                            @foreach ($group->options as $option)
                                <label @class(['so-option', 'is-unavailable' => ! $option->is_available])>
                                    @if ($group->max_select === 1)
                                        <input type="radio" name="g-{{ $group->id }}" wire:model.live="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                    @else
                                        <input type="checkbox" wire:model.live="pickOptions.{{ $group->id }}" value="{{ $option->id }}" @disabled(! $option->is_available)>
                                    @endif
                                    <span>{{ $option->name }}</span>
                                    <span class="so-option-price so-num">{{ $option->is_available ? (\App\Support\Money::isPositive($option->price_delta) ? '+'.$money($option->price_delta) : '') : 'Habis' }}</span>
                                </label>
                            @endforeach
                            @if ($pickErrorGroup === $group->id && $pickError)
                                <em class="so-error" id="g-error-{{ $group->id }}" role="alert">{{ $pickError }}</em>
                            @endif
                        </fieldset>
                    @endforeach

                    <label class="so-field">
                        <span class="so-label">Catatan <small>(opsional)</small></span>
                        <input class="so-input" type="text" maxlength="255" wire:model="pickNotes" placeholder="mis. less sugar">
                    </label>

                    @if ($pickError && $pickErrorGroup === null)
                        <p class="so-error" role="alert">{{ $pickError }}</p>
                    @endif
                </div>

                <div class="so-sheet-foot">
                    <span class="so-qty">
                        <button type="button" wire:click="$set('pickQty', {{ max(1, $pickQty - 1) }})" @disabled($pickQty <= 1) aria-label="Kurangi jumlah">@svg('heroicon-o-minus', ['aria-hidden' => 'true'])</button>
                        <span aria-live="polite">{{ $pickQty }}</span>
                        <button type="button" wire:click="$set('pickQty', {{ min(\App\Actions\SelfOrder\SubmitSelfOrder::MAX_ITEMS, $pickQty + 1) }})" @disabled($pickQty >= \App\Actions\SelfOrder\SubmitSelfOrder::MAX_ITEMS) aria-label="Tambah jumlah">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                    </span>
                    <button type="submit" class="so-btn is-primary is-split" wire:loading.attr="disabled" wire:target="savePick">
                        <span>{{ $pickIndex !== null ? 'Simpan' : 'Tambah' }}</span>
                        <span class="so-num">{{ $money($this->pickTotal()) }}</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
