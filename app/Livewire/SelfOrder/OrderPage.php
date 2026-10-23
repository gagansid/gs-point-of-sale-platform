<?php

declare(strict_types=1);

namespace App\Livewire\SelfOrder;

use App\Actions\Catalog\GetCatalog;
use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Order\QuoteOrder;
use App\Actions\Payment\StartOnlinePayment;
use App\Actions\Payment\StartTableQrisPayment;
use App\Actions\SelfOrder\CheckoutSelfOrder;
use App\Actions\SelfOrder\PayWithPointsFromPhone;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\GatewayTransaction;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\PaymentGatewaySetting;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Models\Voucher;
use App\Services\Order\CalculationResult;
use App\Support\Features;
use App\Support\Money;
use App\Support\OptionDefaults;
use App\Support\SelfOrderContext;
use App\Support\VoucherAttempts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Halaman pesan dari meja di HP (ADR 0014, ADR 0027): menu outlet, keranjang, bayar dulu tanpa terima kasir (QRIS di
 * halaman, e-wallet/VA lewat Snap, bayar di kasir, poin), dan status (polling yang hanya membaca server). Member wajib
 * masuk sebelum bayar bila program member aktif. Harga di layar pratinjau; tagihan dihitung ulang server saat checkout.
 * Pesanan milik sesi browser ini disimpan di session per meja; hanya pesanan itu yang bisa dilihat & dibayar.
 *
 * Request update Livewire tidak melewati middleware route: konteks meja dibaca ulang dari token di boot().
 * Kode voucher baru dihitung ke batas percobaan (VoucherAttempts) sebelum dicek ke database; gagal = pesan seragam
 * (GSPOS-SEC-027).
 *
 * @property-read array{categories: Collection<int, Category>, products: Collection<int, Product>, option_groups: Collection<int, OptionGroup>} $catalog
 * @property-read array{totals: CalculationResult|null, error: string|null} $quote
 * @property-read Collection<int, SelfOrder> $myOrders
 * @property-read bool $onlineReady
 */
final class OrderPage extends Component
{
    #[Locked]
    public string $token = '';

    // menu | cart | status | pay (layar QRIS / Tunjukkan ke kasir)
    #[Locked]
    public string $view = 'menu';

    /** Pencarian menu (nama produk), hanya menyaring tampilan. */
    public string $search = '';

    /**
     * Keranjang; hanya diubah method server (dikunci agar isi tidak bisa dipalsukan dari browser).
     *
     * @var list<array{product_id: string, name: string, qty: int, option_ids: list<string>, option_names: list<string>, notes: string|null}>
     */
    #[Locked]
    public array $cart = [];

    public string $customerName = '';

    public string $notes = '';

    public string $voucherCode = '';

    /** Kode voucher yang sudah lolos batas percobaan; hanya kode ini yang dicek & dikirim. */
    #[Locked]
    public string $checkedVoucher = '';

    /** Pesan bila kode voucher terakhir ditolak batas percobaan (kode tidak dicek). */
    #[Locked]
    public ?string $voucherBlocked = null;

    #[Locked]
    public string $submitId = '';

    public ?string $error = null;

    // Dialog opsi
    public ?string $pickProductId = null;

    /** @var array<string, string|list<string>> */
    public array $pickOptions = [];

    public int $pickQty = 1;

    public string $pickNotes = '';

    public ?string $pickError = null;

    /** Grup opsi yang gagal divalidasi (error tampil di bawah grupnya). */
    public ?string $pickErrorGroup = null;

    /** Baris keranjang yang sedang diubah lewat dialog opsi (null = tambah baru). */
    #[Locked]
    public ?int $pickIndex = null;

    /**
     * Baris terakhir yang dihapus, untuk Urungkan (5 detik di toast).
     *
     * @var array{index: int, line: array{product_id: string, name: string, qty: int, option_ids: list<string>, option_names: list<string>, notes: string|null}}|null
     */
    #[Locked]
    public ?array $removed = null;

    // Bayar: sheet pilihan metode & layar QRIS / Tunjukkan ke kasir
    public bool $payOpen = false;

    /** Pesanan sesi ini yang sedang dibayar (null = keranjang belum menjadi tagihan). */
    #[Locked]
    public ?string $payOrderId = null;

    /** qris | cashier — layar bayar yang sedang tampil. */
    #[Locked]
    public ?string $payMethod = null;

    #[Locked]
    public ?string $payError = null;

    /** Total keranjang yang terakhir tampil & total sebelum dihitung ulang (bila berubah). */
    #[Locked]
    public ?string $shownTotal = null;

    #[Locked]
    public ?string $totalChangedFrom = null;

    private SelfOrderContext $context;

    public function boot(): void
    {
        if (app()->bound(SelfOrderContext::class)) {
            $this->context = SelfOrderContext::get();
        }
    }

    /** Request update: konteks dibaca ulang dari token (meja/QR bisa saja sudah dinonaktifkan). */
    public function hydrate(): void
    {
        $context = SelfOrderContext::resolve($this->token) ?? abort(404);
        SelfOrderContext::set($context);
        $this->context = $context;
    }

    public function mount(): void
    {
        $this->token = $this->context->table->qr_token;
        $this->submitId = (string) Str::uuid7();
        $this->customerName = $this->member()->name ?? '';
        $this->restoreCart();

        // Kembali dari masuk/daftar yang dimulai di Keranjang → langsung ke Keranjang
        $resume = session()->pull(self::resumeKey($this->context->table->id));
        $this->view = match (true) {
            $resume === 'cart' && $this->cart !== [] => 'cart',
            $this->myOrders->isNotEmpty() && $this->cart === [] => 'status',
            default => 'menu',
        };
    }

    /** Keranjang disimpan di session per meja setiap request (bukan di URL), agar utuh setelah masuk/daftar (ADR 0027). */
    public function dehydrate(): void
    {
        session()->put(self::cartKey($this->context->table->id), [
            'cart' => $this->cart,
            'notes' => mb_substr($this->notes, 0, 255),
            'voucher' => $this->checkedVoucher,
        ]);
    }

    public static function cartKey(string $tableId): string
    {
        return 'self_order_cart.'.$tableId;
    }

    public static function resumeKey(string $tableId): string
    {
        return 'self_order_resume.'.$tableId;
    }

    /** Wajib masuk member sebelum mengirim/membayar (ADR 0027 K1) dan belum masuk. */
    public function needsLogin(): bool
    {
        return $this->context->loginRequired() && $this->member() === null;
    }

    /**
     * Pulihkan keranjang dari session: hanya produk & opsi yang masih ada di katalog outlet, nama dari katalog, maks. 50
     * item. Kode voucher yang dipulihkan sudah pernah lolos batas percobaan (VoucherAttempts).
     */
    private function restoreCart(): void
    {
        $saved = session()->get(self::cartKey($this->context->table->id));
        if (! is_array($saved) || ! is_array($saved['cart'] ?? null)) {
            return;
        }

        $products = $this->catalog['products']->keyBy('id');
        $options = $this->catalog['option_groups']->flatMap(fn (OptionGroup $g) => $g->options)->keyBy('id');
        $total = 0;

        foreach ($saved['cart'] as $line) {
            $product = is_array($line) ? $products->get($line['product_id'] ?? '') : null;
            $qty = min((int) ($line['qty'] ?? 0), SubmitSelfOrder::MAX_ITEMS - $total);
            if (! $product instanceof Product || $qty < 1) {
                continue;
            }

            $groupIds = $product->optionGroups->pluck('id')->all();
            $chosen = collect(self::chosenIds($line['option_ids'] ?? []))
                ->map(fn (string $id) => $options->get($id))
                ->filter(fn ($option): bool => $option !== null && in_array($option->option_group_id, $groupIds, true));

            $this->cart[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'option_ids' => $chosen->pluck('id')->values()->all(),
                'option_names' => $chosen->pluck('name')->values()->all(),
                'notes' => is_string($line['notes'] ?? null) && $line['notes'] !== '' ? mb_substr($line['notes'], 0, 255) : null,
            ];
            $total += $qty;
        }

        $this->notes = is_string($saved['notes'] ?? null) ? mb_substr($saved['notes'], 0, 255) : '';
        $voucher = is_string($saved['voucher'] ?? null) ? Voucher::normalizeCode(mb_substr($saved['voucher'], 0, 30)) : '';
        $this->checkedVoucher = $voucher;
        $this->voucherCode = $voucher;
    }

    /** @return array{categories: Collection<int, Category>, products: Collection<int, Product>, option_groups: Collection<int, OptionGroup>} */
    #[Computed]
    public function catalog(): array
    {
        $catalog = app(GetCatalog::class)->handle($this->context->outlet);

        return ['categories' => $catalog['categories']->toBase(), 'products' => $catalog['products']->toBase(), 'option_groups' => $catalog['option_groups']->toBase()];
    }

    /**
     * Menu per kategori (urutan katalog) untuk tab menempel & scroll-spy; pencarian = satu bagian hasil.
     *
     * @return list<array{id: string, name: string, products: Collection<int, Product>}>
     */
    public function menuSections(): array
    {
        $products = $this->catalog['products'];
        $term = mb_strtolower(trim(mb_substr($this->search, 0, 50)));

        if ($term !== '') {
            return [['id' => 'search', 'name' => 'Hasil pencarian', 'products' => $products->filter(fn (Product $p): bool => str_contains(mb_strtolower($p->name), $term))->values()]];
        }

        $sections = [];
        foreach ($this->catalog['categories'] as $category) {
            $items = $products->where('category_id', $category->id)->values();
            if ($items->isNotEmpty()) {
                $sections[] = ['id' => $category->id, 'name' => $category->name, 'products' => $items];
            }
        }

        $known = $this->catalog['categories']->pluck('id')->all();
        $others = $products->reject(fn (Product $p): bool => in_array($p->category_id, $known, true))->values();
        if ($others->isNotEmpty()) {
            $sections[] = ['id' => 'other', 'name' => 'Lainnya', 'products' => $others];
        }

        return $sections;
    }

    /** Produk tanpa grup opsi bisa ditambah langsung dari kartu (tanpa dialog). */
    public static function isQuickAdd(Product $product): bool
    {
        return $product->optionGroups->isEmpty();
    }

    /**
     * Jumlah produk tanpa opsi & catatan di keranjang, untuk stepper di kartu menu.
     *
     * @return array<string, int>
     */
    public function plainQuantities(): array
    {
        $qty = [];
        foreach ($this->cart as $line) {
            if ($line['option_ids'] === [] && $line['notes'] === null) {
                $qty[$line['product_id']] = ($qty[$line['product_id']] ?? 0) + $line['qty'];
            }
        }

        return $qty;
    }

    /**
     * Pratinjau harga satu baris: (harga + tambahan opsi) × qty. Tagihan final tetap dari server.
     *
     * @param  array{product_id: string, qty: int, option_ids: list<string>}  $line
     */
    public function linePrice(array $line): string
    {
        $product = $this->catalog['products']->firstWhere('id', $line['product_id']);
        if (! $product instanceof Product) {
            return '0.00';
        }

        return Money::mul($this->unitPrice($product, $line['option_ids']), $line['qty']);
    }

    /** Harga di tombol dialog opsi: (harga + opsi terpilih) × jumlah. */
    public function pickTotal(): string
    {
        $product = $this->catalog['products']->firstWhere('id', $this->pickProductId);
        if (! $product instanceof Product) {
            return '0.00';
        }

        $ids = [];
        foreach ($this->pickOptions as $chosen) {
            array_push($ids, ...self::chosenIds($chosen));
        }

        return Money::mul($this->unitPrice($product, $ids), max(1, $this->pickQty));
    }

    /**
     * ID opsi terpilih dari input dialog (radio = string, checkbox = daftar); nilai selain teks diabaikan.
     *
     * @return list<string>
     */
    private static function chosenIds(mixed $value): array
    {
        return array_values(array_filter((array) $value, fn (mixed $id): bool => is_string($id) && $id !== ''));
    }

    /** @param  list<string>  $optionIds */
    private function unitPrice(Product $product, array $optionIds): string
    {
        $price = Money::fromDb($product->price);
        foreach ($this->catalog['option_groups'] as $group) {
            foreach ($group->options as $option) {
                if (in_array($option->id, $optionIds, true)) {
                    $price = Money::add($price, Money::fromDb($option->price_delta));
                }
            }
        }

        return $price;
    }

    /** @return array{totals: CalculationResult|null, error: string|null} */
    #[Computed]
    public function quote(): array
    {
        if ($this->cart === []) {
            return ['totals' => null, 'error' => null];
        }

        if ($this->voucherBlocked !== null) {
            return ['totals' => null, 'error' => $this->voucherBlocked];
        }

        try {
            return ['totals' => app(QuoteOrder::class)->handle($this->context->outlet, $this->items(), null, '0.00', $this->member(),
                new BenefitSelection(voucherCode: $this->checkedVoucher !== '' ? $this->checkedVoucher : null)), 'error' => null];
        } catch (BusinessException $e) {
            return ['totals' => null, 'error' => self::publicMessage($e)];
        }
    }

    /** Kode voucher diubah pelanggan: kode baru dihitung ke batas percobaan dulu. */
    public function updatedVoucherCode(): void
    {
        $code = Voucher::normalizeCode(mb_substr($this->voucherCode, 0, 30));
        unset($this->quote);

        if ($code === '' || $code === $this->checkedVoucher) {
            $this->checkedVoucher = $code;
            $this->voucherBlocked = null;

            return;
        }

        try {
            VoucherAttempts::hitPublic((string) request()->ip(), $this->context->table->id);
        } catch (BusinessException $e) {
            $this->checkedVoucher = '';
            $this->voucherBlocked = $e->getMessage();

            return;
        }

        $this->checkedVoucher = $code;
        $this->voucherBlocked = null;
    }

    /** @return Collection<int, SelfOrder> pesanan sesi ini di meja ini, terbaru dulu */
    #[Computed]
    public function myOrders(): Collection
    {
        $ids = (array) session()->get($this->sessionKey(), []);

        return SelfOrder::query()->with(['order:id,order_number,status,grand_total,paid_total,refund_required,accepted_at,completed_at'])->whereIn('id', $ids)
            ->where('dining_table_id', $this->context->table->id)->latest()->limit(10)->get()->toBase();
    }

    public function member(): ?Member
    {
        return $this->context->member();
    }

    // ---------- Menu & keranjang ----------

    public function addProduct(string $productId): void
    {
        $product = $this->catalog['products']->firstWhere('id', $productId);

        if (! $product instanceof Product || ! $product->is_available) {
            $this->error = 'Menu ini sedang habis';

            return;
        }

        $this->error = null;
        $this->reset('pickOptions', 'pickNotes', 'pickError', 'pickErrorGroup', 'pickIndex');
        $this->pickQty = 1;
        $this->pickProductId = $product->id;

        // Opsi bawaan dari owner (Q129), sama dengan kasir web
        foreach ($this->pickerGroups() as $group) {
            $this->pickOptions[$group->id] = OptionDefaults::for($group);
        }
    }

    public function savePick(): void
    {
        $product = $this->catalog['products']->firstWhere('id', $this->pickProductId);

        if (! $product instanceof Product || ! $product->is_available) {
            $this->closePicker();
            $this->error = $product instanceof Product ? 'Menu ini sedang habis' : null;

            return;
        }

        $optionIds = [];
        $names = [];

        foreach ($this->pickerGroups() as $group) {
            $chosen = self::chosenIds($this->pickOptions[$group->id] ?? []);
            $valid = $group->options->whereIn('id', $chosen)->where('is_available', true);

            if (count($chosen) < $group->min_select || count($chosen) > $group->max_select || $valid->count() !== count($chosen)) {
                $this->pickErrorGroup = $group->id;
                $this->pickError = match (true) {
                    $valid->count() !== count($chosen) => "{$group->name}: pilihan sedang habis",
                    $group->min_select === $group->max_select => "Pilih {$group->min_select} {$group->name}",
                    default => "{$group->name}: pilih {$group->min_select}–{$group->max_select}",
                };

                return;
            }

            foreach ($valid as $option) {
                $optionIds[] = $option->id;
                $names[] = $option->name;
            }
        }

        $qty = max(1, min(SubmitSelfOrder::MAX_ITEMS, $this->pickQty));
        $notes = filled(trim($this->pickNotes)) ? mb_substr(trim($this->pickNotes), 0, 255) : null;
        $line = ['product_id' => $product->id, 'name' => $product->name, 'qty' => $qty, 'option_ids' => $optionIds, 'option_names' => $names, 'notes' => $notes];
        $others = $this->cartQty() - ($this->pickIndex !== null ? ($this->cart[$this->pickIndex]['qty'] ?? 0) : 0);

        if ($others + $qty > SubmitSelfOrder::MAX_ITEMS) {
            $this->pickErrorGroup = null;
            $this->pickError = 'Maksimal '.SubmitSelfOrder::MAX_ITEMS.' item per pesanan';

            return;
        }

        if ($this->pickIndex !== null && isset($this->cart[$this->pickIndex])) {
            $this->cart[$this->pickIndex] = $line;
        } elseif (($same = $this->findLine($line)) !== null) {
            // Item yang sama persis (opsi & catatan) digabung, bukan baris baru
            $this->cart[$same]['qty'] += $qty;
        } else {
            $this->cart[] = $line;
        }

        $this->closePicker();
        unset($this->quote);
    }

    /** Tambah langsung dari kartu menu (produk tanpa opsi); produk beropsi membuka dialog. */
    public function quickAdd(string $productId): void
    {
        $product = $this->catalog['products']->firstWhere('id', $productId);

        if (! $product instanceof Product || ! $product->is_available) {
            $this->error = 'Menu ini sedang habis';

            return;
        }

        if (! self::isQuickAdd($product)) {
            $this->addProduct($productId);

            return;
        }

        if ($this->cartQty() >= SubmitSelfOrder::MAX_ITEMS) {
            $this->error = 'Maksimal '.SubmitSelfOrder::MAX_ITEMS.' item per pesanan';

            return;
        }

        $this->error = null;
        $line = ['product_id' => $product->id, 'name' => $product->name, 'qty' => 1, 'option_ids' => [], 'option_names' => [], 'notes' => null];
        $same = $this->findLine($line);
        if ($same !== null) {
            $this->cart[$same]['qty']++;
        } else {
            $this->cart[] = $line;
        }
        unset($this->quote);
    }

    /** Kurangi produk tanpa opsi dari stepper kartu menu. */
    public function decrementProduct(string $productId): void
    {
        $index = $this->findLine(['product_id' => $productId, 'option_ids' => [], 'notes' => null]);

        if ($index !== null) {
            $this->decrement($index);
        }
    }

    /** Ubah opsi/jumlah/catatan satu baris keranjang lewat dialog yang sama. */
    public function editLine(int $index): void
    {
        $line = $this->cart[$index] ?? null;
        $product = $line !== null ? $this->catalog['products']->firstWhere('id', $line['product_id']) : null;

        if (! $product instanceof Product) {
            return;
        }

        $this->reset('pickOptions', 'pickError', 'pickErrorGroup');
        $this->pickProductId = $product->id;
        $this->pickIndex = $index;
        $this->pickQty = $line['qty'];
        $this->pickNotes = (string) $line['notes'];

        foreach ($this->pickerGroups() as $group) {
            $chosen = $group->options->whereIn('id', $line['option_ids'])->pluck('id')->values()->all();
            $this->pickOptions[$group->id] = $group->max_select === 1 ? ($chosen[0] ?? '') : $chosen;
        }
    }

    /** Hapus baris; bisa diurungkan dari toast. */
    public function removeLine(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $this->removed = ['index' => $index, 'line' => $this->cart[$index]];
        array_splice($this->cart, $index, 1);
        unset($this->quote);
        $this->dispatch('so-toast', message: $this->removed['line']['name'].' dihapus', undo: true);
    }

    public function undoRemove(): void
    {
        if ($this->removed === null || $this->cartQty() + $this->removed['line']['qty'] > SubmitSelfOrder::MAX_ITEMS) {
            return;
        }

        array_splice($this->cart, min($this->removed['index'], count($this->cart)), 0, [$this->removed['line']]);
        $this->removed = null;
        unset($this->quote);
    }

    public function closePicker(): void
    {
        $this->reset('pickProductId', 'pickOptions', 'pickQty', 'pickNotes', 'pickError', 'pickErrorGroup', 'pickIndex');
    }

    /** @return Collection<int, OptionGroup> */
    public function pickerGroups(): Collection
    {
        $product = $this->catalog['products']->firstWhere('id', $this->pickProductId);
        $ids = $product instanceof Product ? $product->optionGroups->pluck('id')->all() : [];

        return $this->catalog['option_groups']->whereIn('id', $ids)
            ->sortBy(fn (OptionGroup $g): int|false => array_search($g->id, $ids, true))->values();
    }

    public function increment(int $index): void
    {
        if (isset($this->cart[$index]) && $this->cartQty() < SubmitSelfOrder::MAX_ITEMS) {
            $this->cart[$index]['qty']++;
            unset($this->quote);
        }
    }

    /** Qty 1 → baris dihapus (bisa diurungkan). */
    public function decrement(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        if ($this->cart[$index]['qty'] <= 1) {
            $this->removeLine($index);

            return;
        }

        $this->cart[$index]['qty']--;
        unset($this->quote);
    }

    public function cartQty(): int
    {
        return (int) array_sum(array_column($this->cart, 'qty'));
    }

    /**
     * Baris keranjang yang sama persis (produk, opsi, catatan).
     *
     * @param  array{product_id: string, option_ids: list<string>, notes: string|null}  $line
     */
    private function findLine(array $line): ?int
    {
        $options = $line['option_ids'];
        sort($options);

        foreach ($this->cart as $i => $existing) {
            $existingOptions = $existing['option_ids'];
            sort($existingOptions);

            if ($existing['product_id'] === $line['product_id'] && $existingOptions === $options && $existing['notes'] === $line['notes']) {
                return $i;
            }
        }

        return null;
    }

    public function show(string $view): void
    {
        $this->view = in_array($view, ['menu', 'cart', 'status'], true) ? $view : 'menu';
        $this->error = null;
        $this->payOpen = false;
    }

    // ---------- Bayar (ADR 0027: bayar dulu tanpa terima kasir) ----------

    /**
     * Buka pilihan bayar untuk keranjang (`$selfOrderId` null) atau pesanan sesi ini yang menunggu bayar. Total keranjang
     * dihitung ulang server sekarang; bila berbeda dari yang terakhir tampil, perbedaannya ditunjukkan di sheet.
     */
    public function openPay(?string $selfOrderId = null): void
    {
        $this->error = null;
        $this->payError = null;
        $this->totalChangedFrom = null;

        if ($selfOrderId !== null) {
            $selfOrder = $this->myOrders->firstWhere('id', $selfOrderId);
            if ($selfOrder?->order?->status !== OrderStatus::AwaitingPayment) {
                return;
            }
            $this->payOrderId = $selfOrder->id;
        } else {
            if ($this->cart === []) {
                return;
            }
            if ($this->needsLogin()) {
                $this->error = 'Masuk sebagai member dulu untuk membayar. Keranjangmu tetap tersimpan.';

                return;
            }

            $this->payOrderId = null;
            $before = $this->shownTotal;
            unset($this->quote);
            $now = $this->quote['totals']?->grandTotal;

            if ($this->quote['error'] !== null || $now === null) {
                $this->error = $this->quote['error'] ?? 'Keranjang belum bisa dibayar';

                return;
            }
            if ($before !== null && Money::compare($before, $now) !== 0) {
                $this->totalChangedFrom = $before;
            }
        }

        $this->payOpen = true;
    }

    public function closePay(): void
    {
        $this->payOpen = false;
        $this->payError = null;
    }

    /**
     * Metode bayar yang tersedia untuk pesanan/keranjang yang sedang dibayar (yang tidak tersedia disembunyikan).
     *
     * @return list<string> qris | ewallet | cashier | points
     */
    public function payMethods(): array
    {
        $setting = $this->context->setting;
        $gateway = PaymentGatewaySetting::current();
        $online = Features::enabled('online_payment') && $gateway->isReady();
        $methods = [];

        if (StartTableQrisPayment::available($gateway, $setting)) {
            $methods[] = 'qris';
        }
        if ($online && StartOnlinePayment::channels($gateway) !== []) {
            $methods[] = 'ewallet';
        }
        // Tanpa metode online, bayar di kasir selalu tersedia agar pesanan tetap bisa dibayar
        if ($setting->allow_pay_at_cashier || $methods === []) {
            $methods[] = 'cashier';
        }
        if ($setting->allow_points && $this->pointsAvailable() > 0) {
            $methods[] = 'points';
        }

        return $methods;
    }

    /** Poin yang bisa dipakai: tagihan sudah ada = aturan program; keranjang = saldo cukup untuk minimal tukar. */
    public function pointsAvailable(): int
    {
        $member = $this->member();
        if ($member === null) {
            return 0;
        }

        $order = $this->payOrder();
        if ($order !== null) {
            return $order->member_id === $member->id && $order->status === OrderStatus::AwaitingPayment ? PayWithPointsFromPhone::maxPoints($order, $member) : 0;
        }

        $settings = MemberSetting::current();

        return $settings->enabled && $member->points_balance >= max(1, $settings->min_redeem_points) ? $member->points_balance : 0;
    }

    /** Pilih metode: keranjang dijadikan tagihan dulu (sekali, idempotent), lalu metode dijalankan. */
    public function choosePay(string $method): void
    {
        $this->payError = null;

        if (! in_array($method, $this->payMethods(), true)) {
            $this->payError = 'Metode bayar ini sedang tidak tersedia';

            return;
        }

        $order = $this->payOrderId !== null ? $this->payOrder() : $this->checkout();
        if ($order === null) {
            return;
        }

        if ($order->status === OrderStatus::Completed) {
            $this->finishPaid();

            return;
        }

        try {
            match ($method) {
                'qris' => app(StartTableQrisPayment::class)->handle($order, (string) request()->ip()),
                'points' => app(PayWithPointsFromPhone::class)->handle($order, $this->member() ?? throw BusinessException::of(ErrorCode::Unauthenticated)),
                'ewallet' => $this->redirect((string) app(StartOnlinePayment::class)->handle($order, (string) $this->payingSelfOrder()?->customer_name, $this->context->url())->redirect_url),
                default => null,
            };
        } catch (BusinessException $e) {
            $this->payError = $e->getMessage();

            return;
        }

        if ($method === 'points') {
            $order->refresh();
            if ($order->status === OrderStatus::Completed) {
                $this->finishPaid();
            } else {
                // Sisa tagihan dibayar dengan metode lain
                $this->dispatch('so-toast', message: 'Poin dipakai. Sisa '.Money::format(Money::sub($order->grand_total, $order->paid_total)).' bayar dengan metode lain');
            }

            return;
        }

        if ($method !== 'ewallet') {
            $this->payMethod = $method;
            $this->payOpen = false;
            $this->view = 'pay';
        }
    }

    /** Layar QRIS kedaluwarsa: QR baru (QR lama dibatalkan). */
    public function renewQris(): void
    {
        $order = $this->payOrder();
        if ($order === null) {
            return;
        }

        try {
            app(StartTableQrisPayment::class)->handle($order, (string) request()->ip(), renew: true);
            $this->payError = null;
        } catch (BusinessException $e) {
            $this->payError = $e->getMessage();
        }
    }

    /** Pindah ke bayar di kasir dari layar QRIS. */
    public function payAtCashier(): void
    {
        if (in_array('cashier', $this->payMethods(), true) && $this->payOrder() !== null) {
            $this->payMethod = 'cashier';
            $this->view = 'pay';
        }
    }

    /** Polling layar bayar: hanya membaca status dari server; lunas → ke Pesananku. */
    public function checkPayment(): void
    {
        $order = $this->payOrder();

        if ($order?->status === OrderStatus::Completed) {
            $this->finishPaid();
        } elseif ($order === null || $order->status !== OrderStatus::AwaitingPayment) {
            $this->view = 'status';
        }
    }

    /** Pesanan yang sedang dibayar (hanya milik sesi ini). */
    public function payOrder(): ?Order
    {
        $selfOrder = $this->payingSelfOrder();

        return $selfOrder?->order_id !== null ? Order::query()->find($selfOrder->order_id) : null;
    }

    public function payingSelfOrder(): ?SelfOrder
    {
        return $this->payOrderId !== null ? $this->myOrders->firstWhere('id', $this->payOrderId) : null;
    }

    /** QR QRIS terbaru untuk pesanan yang sedang dibayar (aktif atau terakhir). */
    public function qrisTransaction(): ?GatewayTransaction
    {
        $order = $this->payOrder();

        return $order !== null ? GatewayTransaction::query()->where('order_id', $order->id)->where('method', GatewayTransaction::QRIS)->latest()->first() : null;
    }

    /** Batas waktu bayar pesanan (zona outlet). */
    public function payDeadline(Order $order): CarbonImmutable
    {
        return CarbonImmutable::parse($order->accepted_at ?? $order->created_at)->addMinutes($this->context->setting->payment_timeout_minutes)
            ->setTimezone($this->context->outlet->timezone);
    }

    /**
     * Keranjang → tagihan (CheckoutSelfOrder). Berhasil: keranjang dikosongkan, pesanan masuk daftar sesi ini.
     */
    private function checkout(): ?Order
    {
        if ($this->voucherBlocked !== null) {
            $this->payError = $this->voucherBlocked;

            return null;
        }

        try {
            $result = app(CheckoutSelfOrder::class)->handle($this->context, [
                'id' => $this->submitId,
                'customer_name' => mb_substr(trim($this->customerName), 0, 100),
                'items' => $this->items(),
                'notes' => filled(trim($this->notes)) ? mb_substr(trim($this->notes), 0, 255) : null,
                'voucher_code' => $this->checkedVoucher !== '' ? $this->checkedVoucher : null,
            ], $this->member(), (string) request()->ip());
        } catch (ValidationException $e) {
            $this->payError = $e->validator->errors()->first();

            return null;
        } catch (BusinessException $e) {
            $this->payError = self::publicMessage($e);

            return null;
        }

        session()->put($this->sessionKey(), array_values(array_unique([...(array) session()->get($this->sessionKey(), []), $result['self_order']->id])));
        $this->reset('cart', 'notes', 'voucherCode', 'checkedVoucher', 'voucherBlocked', 'shownTotal', 'totalChangedFrom');
        $this->submitId = (string) Str::uuid7();
        $this->payOrderId = $result['self_order']->id;
        unset($this->myOrders, $this->quote);

        return $result['order'];
    }

    private function finishPaid(): void
    {
        $this->payOpen = false;
        $this->payMethod = null;
        $this->view = 'status';
        unset($this->myOrders);
        $this->dispatch('so-toast', message: 'Pembayaran diterima. Pesananmu masuk antrean');
    }

    /** Total keranjang yang terakhir tampil, untuk menunjukkan perubahan harga saat sheet bayar dibuka. */
    public function rendering(): void
    {
        if ($this->view === 'cart' && $this->cart !== [] && ! $this->payOpen) {
            $this->shownTotal = $this->quote['totals']?->grandTotal;
        }
    }

    public function render(): View
    {
        return view('livewire.self-order.order-page', [
            'context' => $this->context,
            'statusLabel' => fn (SelfOrder $o): array => self::statusOf($o),
        ]);
    }

    /**
     * Status untuk pelanggan: label, nada, keterangan.
     *
     * @return array{label: string, tone: string, note: string}
     */
    public static function statusOf(SelfOrder $selfOrder): array
    {
        $order = $selfOrder->order;

        return match (true) {
            $selfOrder->status === SelfOrderStatus::Pending => ['label' => 'Menunggu kasir', 'tone' => 'warning', 'note' => 'Kasir akan memeriksa pesananmu sebentar lagi.'],
            $selfOrder->status === SelfOrderStatus::Rejected => ['label' => 'Ditolak', 'tone' => 'danger', 'note' => (string) $selfOrder->reject_reason],
            $selfOrder->status === SelfOrderStatus::Expired => ['label' => 'Kedaluwarsa', 'tone' => 'muted', 'note' => 'Pesanan terlalu lama menunggu. Silakan pesan lagi atau ke kasir.'],
            $order?->status === OrderStatus::AwaitingPayment => ['label' => 'Menunggu bayar', 'tone' => 'warning', 'note' => 'Selesaikan pembayaran agar pesanan disiapkan.'],
            $order?->status === OrderStatus::Completed => ['label' => 'Lunas', 'tone' => 'success', 'note' => 'Terima kasih! Pesananmu masuk antrean.'],
            $order?->status === OrderStatus::Expired => ['label' => 'Kedaluwarsa', 'tone' => 'muted', 'note' => 'Pesanan tidak dibayar dalam batas waktu.'],
            $order?->status === OrderStatus::Voided => ['label' => 'Dibatalkan', 'tone' => 'danger', 'note' => $order->refund_required ? 'Pesanan dibatalkan outlet. Dana dikembalikan oleh outlet.' : 'Pesanan dibatalkan outlet.'],
            default => ['label' => 'Diterima', 'tone' => 'info', 'note' => ''],
        };
    }

    /** @return list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null}> */
    private function items(): array
    {
        return array_map(fn (array $l): array => ['product_id' => $l['product_id'], 'qty' => $l['qty'], 'option_ids' => $l['option_ids'], 'notes' => $l['notes']], $this->cart);
    }

    private function sessionKey(): string
    {
        return 'self_orders.'.$this->context->table->id;
    }

    /** Alasan voucher tidak sah tidak ditampilkan ke publik (kode yang ada tidak bisa dibedakan). */
    private static function publicMessage(BusinessException $e): string
    {
        return $e->errorCode === ErrorCode::VoucherInvalid->value ? VoucherAttempts::PUBLIC_INVALID_MESSAGE : $e->getMessage();
    }
}
