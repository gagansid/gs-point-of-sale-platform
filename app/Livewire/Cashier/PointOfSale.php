<?php

declare(strict_types=1);

namespace App\Livewire\Cashier;

use App\Actions\Catalog\GetCatalog;
use App\Actions\Member\Data\MemberData;
use App\Actions\Member\ResolveMemberPricing;
use App\Actions\Member\SaveMember;
use App\Actions\Order\AddPayment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\QuoteOrder;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\VisibleOrders;
use App\Actions\Product\SetProductAvailability;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Http\Requests\Api\V1\Order\CheckoutRequest;
use App\Http\Requests\Api\V1\Order\OpenBillRequest;
use App\Models\Category;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\CalculationResult;
use App\Support\CurrentOutlet;
use App\Support\Money;
use App\Support\Phone;
use App\Support\WebCashier;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Layar transaksi kasir web (Q54): katalog outlet, keranjang, bayar (tunai/non-tunai/split), struk.
 *
 * Keranjang di state Livewire hanya berisi ID & teks tampilan; harga & total selalu dihitung ulang
 * server (QuoteOrder untuk pratinjau, CheckoutOrder saat bayar) — nilai dari browser diabaikan.
 *
 * @property-read Collection<int, PaymentMethod> $paymentMethods
 * @property-read array{categories: Collection<int, Category>, products: Collection<int, Product>, option_groups: Collection<int, OptionGroup>, payment_methods: Collection<int, PaymentMethod>} $catalog
 * @property-read Collection<int, Product> $visibleProducts
 * @property-read array{totals: CalculationResult|null, error: string|null} $quote
 * @property-read Collection<int, Order> $openBills
 * @property-read Collection<int, User> $approvers
 */
final class PointOfSale extends Component
{
    /** Nominal cepat uang tunai */
    public const QUICK_CASH = ['20000', '50000', '100000'];

    public string $search = '';

    /** null = semua, 'favorites' = favorit, selain itu ID kategori */
    public ?string $category = null;

    /** @var list<array{product_id: string, name: string, qty: int, option_ids: list<string>, option_names: list<string>, notes: string|null, discount: string}> */
    public array $cart = [];

    public string $orderType = 'takeaway';

    public string $tableLabel = '';

    public string $notes = '';

    /** '' = tanpa diskon order, selain itu DiscountType */
    public string $discountType = '';

    public string $discountValue = '';

    /** Idempotency key order (Q: ID order dari klien): klik ganda / kirim ulang tetap satu order */
    #[Locked]
    public string $orderId = '';

    // Dialog pilih opsi / ubah item
    public ?string $pickProductId = null;

    public ?int $pickIndex = null;

    /** @var array<string, list<string>|string> grup → ID opsi (radio: string, checkbox: list) */
    public array $pickOptions = [];

    public int $pickQty = 1;

    public string $pickNotes = '';

    public string $pickDiscount = '';

    public ?string $pickError = null;

    // Dialog pembayaran
    public bool $paying = false;

    /** @var list<array{id: string, payment_method_id: string, amount: string, tendered: string, reference: string}> */
    public array $payments = [];

    public ?string $payError = null;

    /** Open bill yang sedang dibuka di keranjang (null = order baru); orderId = billId */
    #[Locked]
    public ?string $billId = null;

    /** Sudah dibayar sebelumnya pada open bill (tampilan; server menghitung ulang) */
    #[Locked]
    public string $billPaid = '0.00';

    public bool $showBills = false;

    /** Label opsional keranjang yang ditahan, mis. "Mbak baju merah" */
    public string $holdLabel = '';

    // Approval PIN (diskon di atas batas role): muncul setelah server meminta
    public bool $needsApproval = false;

    public string $approverId = '';

    public string $approverPin = '';

    // Member (ADR 0013): dipasang ke order; server memvalidasi ulang saat simpan/bayar (ResolveOrderMember)
    public ?string $memberId = null;

    public string $memberName = '';

    public bool $showMembers = false;

    public string $memberSearch = '';

    /** @var list<array{id: string, name: string, code: string, phone: string, last_visit: string|null}> */
    public array $memberResults = [];

    public string $newMemberName = '';

    public string $newMemberPhone = '';

    public bool $newMemberConsent = false;

    public ?string $memberError = null;

    // Bukti pemilik saat membayar dengan poin (ADR 0013); tidak disimpan setelah request
    public string $memberCode = '';

    public string $memberPin = '';

    #[Locked]
    public ?string $completedOrderId = null;

    public ?string $completedChange = null;

    public function mount(): void
    {
        $this->orderId = (string) Str::uuid7();
    }

    /** @return array{categories: Collection<int, Category>, products: Collection<int, Product>, option_groups: Collection<int, OptionGroup>, payment_methods: Collection<int, PaymentMethod>} */
    #[Computed]
    public function catalog(): array
    {
        return app(GetCatalog::class)->handle($this->outlet());
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function visibleProducts(): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        return $this->catalog['products']->filter(fn (Product $p): bool => match (true) {
            $this->category === 'favorites' && ! $p->is_favorite => false,
            $this->category !== null && $this->category !== 'favorites' && $p->category_id !== $this->category => false,
            $needle === '' => true,
            default => str_contains(mb_strtolower($p->name), $needle)
                || mb_strtolower((string) $p->sku) === $needle
                || (string) $p->barcode === trim($this->search),
        })->values();
    }

    /**
     * Pratinjau total dari server; error produk/opsi (mis. stok habis) ditampilkan di keranjang.
     *
     * @return array{totals: CalculationResult|null, error: string|null}
     */
    #[Computed]
    public function quote(): array
    {
        if ($this->cart === []) {
            return ['totals' => null, 'error' => null];
        }

        try {
            // Diskon member ikut pratinjau; ID dari browser dicari ulang dalam tenant aktif
            $member = $this->memberId !== null ? Member::query()->find($this->memberId) : null;

            return ['totals' => app(QuoteOrder::class)->handle($this->outlet(), $this->checkoutItems(), $this->orderDiscountType(), $this->orderDiscountValue(),
                app(ResolveMemberPricing::class)->handle($member, $this->outlet())), 'error' => null];
        } catch (BusinessException $e) {
            return ['totals' => null, 'error' => self::describe($e)];
        }
    }

    /** @return Collection<int, Order> open bill outlet ini yang boleh dilihat (Q21) */
    #[Computed]
    public function openBills(): Collection
    {
        return VisibleOrders::query($this->user())->where('status', OrderStatus::Open)
            ->with('user:id,name')->latest()->limit(50)->get();
    }

    /** @return Collection<int, User> calon approver: aktif, punya PIN, di outlet ini, bukan diri sendiri */
    #[Computed]
    public function approvers(): Collection
    {
        $outletId = $this->outlet()->id;

        return User::query()->where('is_active', true)->whereNotNull('pin')->whereKeyNot($this->user()->id)
            ->orderBy('name')->get()
            ->filter(fn (User $u): bool => $u->can('order.discount') && $u->canAccessOutlet($outletId))->values();
    }

    public function updatedDiscountType(): void
    {
        if ($this->discountType === '') {
            $this->discountValue = '';
        }
    }

    // ---------- Keranjang ----------

    public function addProduct(string $productId): void
    {
        $product = $this->product($productId);

        if ($product === null || ! $product->is_available) {
            $this->toast('Produk tidak tersedia', 'warning');

            return;
        }

        if ($product->optionGroups->isNotEmpty()) {
            $this->openPicker($product);

            return;
        }

        $this->putLine(['product_id' => $product->id, 'name' => $product->name, 'qty' => 1, 'option_ids' => [], 'option_names' => [], 'notes' => null, 'discount' => '0.00']);
    }

    /**
     * Masukkan baris ke keranjang. Produk & pilihan opsi sama, tanpa catatan & tanpa diskon item → qty digabung
     * ke baris yang ada; beda opsi/catatan/diskon → baris baru. $replace = indeks baris yang sedang diubah.
     *
     * @param  array{product_id: string, name: string, qty: int, option_ids: list<string>, option_names: list<string>, notes: ?string, discount: string}  $line
     */
    private function putLine(array $line, ?int $replace = null): void
    {
        $mergeable = fn (array $l): bool => $l['notes'] === null && $l['discount'] === '0.00';
        /** @param array{option_ids: list<string>} $l */
        $options = function (array $l): array {
            $ids = $l['option_ids'];
            sort($ids);

            return $ids;
        };

        if ($mergeable($line)) {
            foreach ($this->cart as $i => $existing) {
                if ($i !== $replace && $existing['product_id'] === $line['product_id'] && $mergeable($existing)
                    && $options($existing) === $options($line)) {
                    $this->cart[$i]['qty'] = min(999, $existing['qty'] + $line['qty']);

                    if ($replace !== null) {
                        $this->remove($replace);
                    }

                    return;
                }
            }
        }

        if ($replace !== null && isset($this->cart[$replace])) {
            $this->cart[$replace] = $line;

            return;
        }

        $this->cart[] = $line;
    }

    /** Enter di kolom cari: barcode/SKU persis (scanner USB) langsung masuk keranjang. */
    public function scan(): void
    {
        $code = trim($this->search);
        $match = $this->catalog['products']->first(fn (Product $p): bool => $code !== ''
            && ((string) $p->barcode === $code || mb_strtolower((string) $p->sku) === mb_strtolower($code)));
        $match ??= $this->visibleProducts->count() === 1 ? $this->visibleProducts->first() : null;

        if ($match !== null) {
            $this->addProduct($match->id);
            $this->search = '';
        }
    }

    public function increment(int $index): void
    {
        if (isset($this->cart[$index])) {
            $this->cart[$index]['qty'] = min(999, $this->cart[$index]['qty'] + 1);
        }
    }

    public function decrement(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $this->cart[$index]['qty'] > 1 ? $this->cart[$index]['qty']-- : $this->remove($index);
    }

    public function remove(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    public function clearCart(): void
    {
        $this->resetOrder();
    }

    /** Toggle "habis" langsung dari kasir (product.toggle_available). */
    public function toggleAvailability(string $productId): void
    {
        abort_unless($this->user()->can('product.toggle_available'), 403);
        // Hanya produk yang dijual di outlet ini (katalog outlet), bukan seluruh tenant
        $product = $this->product($productId) ?? abort(404);

        app(SetProductAvailability::class)->handle(Product::query()->findOrFail($product->id), $this->outlet(), ! $product->is_available);
        unset($this->catalog, $this->visibleProducts);
    }

    // ---------- Dialog opsi / ubah item ----------

    public function editLine(int $index): void
    {
        $line = $this->cart[$index] ?? null;
        $product = $line !== null ? $this->product($line['product_id']) : null;

        if ($line === null || $product === null) {
            return;
        }

        $this->openPicker($product);
        $this->pickIndex = $index;
        $this->pickQty = $line['qty'];
        $this->pickNotes = (string) $line['notes'];
        $this->pickDiscount = $line['discount'] === '0.00' ? '' : $line['discount'];

        foreach ($this->pickerGroups() as $group) {
            $chosen = array_values(array_intersect($group->options->pluck('id')->all(), $line['option_ids']));
            $this->pickOptions[$group->id] = $group->max_select === 1 ? ($chosen[0] ?? '') : $chosen;
        }
    }

    public function savePick(): void
    {
        $product = $this->pickProductId !== null ? $this->product($this->pickProductId) : null;

        if ($product === null) {
            $this->closePicker();

            return;
        }

        $optionIds = [];
        $names = [];

        foreach ($this->pickerGroups() as $group) {
            $chosen = array_values(array_filter((array) ($this->pickOptions[$group->id] ?? []), 'is_string'));
            $chosen = array_values(array_filter($chosen, fn (string $id): bool => $id !== ''));

            if (count($chosen) < $group->min_select || count($chosen) > $group->max_select) {
                $this->pickError = $group->min_select === $group->max_select
                    ? "Pilih {$group->min_select} {$group->name}"
                    : "{$group->name}: pilih {$group->min_select}–{$group->max_select}";

                return;
            }

            foreach ($group->options->whereIn('id', $chosen) as $option) {
                $optionIds[] = $option->id;
                $names[] = $option->name;
            }
        }

        $discount = trim($this->pickDiscount);
        if ($discount !== '' && (! is_numeric($discount) || (float) $discount < 0 || ! preg_match('/^\d+(\.\d{1,2})?$/', $discount))) {
            $this->pickError = 'Diskon item harus angka ≥ 0 (maks. 2 desimal)';

            return;
        }

        $line = [
            'product_id' => $product->id,
            'name' => $product->name,
            'qty' => max(1, min(999, $this->pickQty)),
            'option_ids' => $optionIds,
            'option_names' => $names,
            'notes' => filled(trim($this->pickNotes)) ? mb_substr(trim($this->pickNotes), 0, 255) : null,
            'discount' => Money::of($discount === '' ? '0' : $discount),
        ];

        $this->putLine($line, $this->pickIndex !== null && isset($this->cart[$this->pickIndex]) ? $this->pickIndex : null);
        $this->closePicker();
    }

    public function closePicker(): void
    {
        $this->reset('pickProductId', 'pickIndex', 'pickOptions', 'pickQty', 'pickNotes', 'pickDiscount', 'pickError');
    }

    /** @return Collection<int, OptionGroup> */
    public function pickerGroups(): Collection
    {
        $product = $this->pickProductId !== null ? $this->product($this->pickProductId) : null;
        $ids = $product?->optionGroups->pluck('id')->all() ?? [];

        // Urutan grup = urutan di produk
        return $this->catalog['option_groups']->whereIn('id', $ids)
            ->sortBy(fn (OptionGroup $g): int|false => array_search($g->id, $ids, true))->values();
    }

    // ---------- Pembayaran ----------

    public function openPayment(): void
    {
        $totals = $this->quote['totals'];

        if ($totals === null) {
            $this->toast($this->quote['error'] ?? 'Keranjang kosong', 'warning');

            return;
        }

        $cash = $this->catalog['payment_methods']->first(fn (PaymentMethod $m): bool => $m->category === PaymentCategory::Cash)
            ?? $this->catalog['payment_methods']->first();

        $due = Money::sub($totals->grandTotal, $this->billPaid);
        $this->payments = Money::isPositive($due) && $cash !== null ? [self::paymentRow($cash->id, $due)] : [];
        $this->payError = null;
        $this->paying = true;
    }

    public function addPaymentRow(): void
    {
        $remaining = $this->remaining();
        $method = $this->paymentMethods->first(fn (PaymentMethod $m): bool => $m->category !== PaymentCategory::Points);

        if ($method !== null && count($this->payments) < 10) {
            $this->payments[] = self::paymentRow($method->id, Money::isPositive($remaining) ? $remaining : '0.00');
        }
    }

    public function removePaymentRow(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    /** Pilih metode bayar lewat kartu; hanya metode aktif di outlet ini. */
    public function setMethod(int $index, string $methodId): void
    {
        if (isset($this->payments[$index]) && $this->paymentMethods->contains('id', $methodId)) {
            $this->payments[$index]['payment_method_id'] = $methodId;
        }
    }

    /** Tombol uang pas ('exact') / nominal cepat (pecahan tetap) untuk baris tunai. */
    public function setTendered(int $index, string $preset): void
    {
        if (! isset($this->payments[$index])) {
            return;
        }

        $this->payments[$index]['tendered'] = match (true) {
            $preset === 'exact' => $this->payments[$index]['amount'],
            in_array($preset, self::QUICK_CASH, true) => $preset,
            default => $this->payments[$index]['tendered'],
        };
    }

    public function pay(): void
    {
        abort_unless($this->user()->can('order.create'), 403);
        $this->payError = null;

        $data = [
            'id' => $this->orderId,
            'order_type' => $this->orderType,
            'table_label' => $this->orderType === 'dine_in' && filled(trim($this->tableLabel)) ? trim($this->tableLabel) : null,
            'notes' => filled(trim($this->notes)) ? trim($this->notes) : null,
            'member_id' => $this->memberId,
            'discount' => $this->orderDiscountType() !== null ? ['type' => $this->discountType, 'value' => $this->orderDiscountValue()] : null,
            'items' => $this->checkoutItems(),
            'payments' => array_map(fn (array $p): array => [
                'id' => $p['id'],
                'payment_method_id' => $p['payment_method_id'],
                'amount' => trim($p['amount']),
                'tendered' => $this->isCash($p['payment_method_id']) && filled(trim($p['tendered'])) ? trim($p['tendered']) : null,
                'reference' => ! $this->isCash($p['payment_method_id']) && filled(trim($p['reference'])) ? trim($p['reference']) : null,
            ], $this->payments),
        ];

        $data = [...$data, ...$this->approvalInput(), ...$this->memberProofInput()];
        $this->memberPin = '';
        // PIN hanya dipakai di request ini; tidak boleh tertinggal di snapshot Livewire (terlihat di DOM)
        $this->approverPin = '';

        // Aturan bentuk data sama dengan API POST /checkout
        $request = new CheckoutRequest([], $data);
        $validator = Validator::make($data, $request->rules(), [], $request->attributes());

        if ($validator->fails()) {
            $this->payError = $validator->errors()->first();

            return;
        }

        try {
            $order = $this->billId === null
                ? app(CheckoutOrder::class)->handle($this->user(), WebCashier::device(), CheckoutData::fromArray($data))['order']
                : $this->payBill($data);
        } catch (BusinessException $e) {
            $this->payError = $this->failed($e);

            return;
        } catch (ValidationException $e) {
            $this->payError = $e->validator->errors()->first();

            return;
        }

        $this->completedOrderId = $order->id;
        $this->completedChange = $order->change_total;
        $this->resetOrder();
    }

    // ---------- Tahan transaksi (park) ----------

    // ---------- Member (ADR 0013) ----------

    /** Batas pencarian member per user per menit (cegah menebak nomor HP), sama dengan API */
    private const MEMBER_SEARCH_PER_MINUTE = 30;

    #[Computed]
    public function memberProgramEnabled(): bool
    {
        return $this->user()->can('member.view') && MemberSetting::current()->enabled;
    }

    /**
     * Metode bayar yang bisa dipilih: "Poin" hanya bila member terpasang & program aktif di outlet ini.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function paymentMethods(): Collection
    {
        $points = $this->memberId !== null && OutletMemberProgram::activeAt($this->outlet()->id);

        return $this->catalog['payment_methods']->filter(fn (PaymentMethod $m): bool => $m->category !== PaymentCategory::Points || $points)->values();
    }

    public function usesPoints(): bool
    {
        foreach ($this->payments as $payment) {
            if ($this->paymentMethods->firstWhere('id', $payment['payment_method_id'])?->category === PaymentCategory::Points) {
                return true;
            }
        }

        return false;
    }

    /** Saldo poin member terpasang (dibaca ulang tiap render: bisa berubah dari kasir lain). */
    #[Computed]
    public function memberPoints(): ?int
    {
        return $this->memberId !== null ? Member::query()->whereKey($this->memberId)->value('points_balance') : null;
    }

    public function updatedMemberSearch(): void
    {
        $this->memberResults = [];
        $term = trim($this->memberSearch);

        if (! $this->memberProgramEnabled() || mb_strlen($term) < 2) {
            return;
        }

        $key = 'member-search:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::MEMBER_SEARCH_PER_MINUTE)) {
            $this->memberError = 'Terlalu banyak pencarian. Coba lagi dalam '.RateLimiter::availableIn($key).' detik';

            return;
        }
        RateLimiter::hit($key, 60);
        $this->memberError = null;

        $this->memberResults = Member::query()->whereNull('anonymized_at')->where('is_active', true)
            ->search($term)->orderByDesc('last_visit_at')->orderBy('name')->limit(8)->get()
            ->map(fn (Member $m): array => [
                'id' => $m->id, 'name' => $m->name, 'code' => $m->code, 'phone' => $m->maskedPhone(),
                // Pembeda nama yang sama: kode, 4 digit HP, kunjungan terakhir
                'last_visit' => $m->last_visit_at?->setTimezone($this->outlet()->timezone)->locale('id')->translatedFormat('j M Y'),
            ])
            ->all();
    }

    public function selectMember(string $memberId): void
    {
        abort_unless($this->memberProgramEnabled(), 403);
        // ID dari browser: dicari ulang dalam tenant aktif
        $member = Member::query()->find($memberId);

        if ($member === null || ! $member->isUsable()) {
            $this->memberError = 'Member tidak ditemukan atau tidak aktif';

            return;
        }

        $this->memberId = $member->id;
        $this->memberName = $member->name;
        $this->reset('showMembers', 'memberSearch', 'memberResults', 'newMemberName', 'newMemberPhone', 'newMemberConsent', 'memberError');
    }

    /**
     * Enter di kolom member (ketik atau scanner barcode): kode/nomor HP persis → langsung dipasang;
     * selain itu satu-satunya hasil pencarian.
     */
    public function pickMember(): void
    {
        $term = trim($this->memberSearch);

        if (! $this->memberProgramEnabled() || $term === '') {
            return;
        }

        // QR halaman member "KODEMEMBER-123456": pasang member + simpan kode bukti untuk menukar poin
        if (preg_match('/^([A-Za-z0-9]{6})-(\d{6})$/', $term, $match) === 1) {
            $term = $match[1];
            $this->memberCode = $match[2];
        }

        $phone = Phone::normalize($term);
        $exact = Member::query()->whereNull('anonymized_at')->where('is_active', true)
            ->where(fn ($q) => $q->where('code', mb_strtoupper($term))->when($phone !== null, fn ($w) => $w->orWhere('phone', $phone)))
            ->value('id');

        $id = $exact ?? (count($this->memberResults) === 1 ? $this->memberResults[0]['id'] : null);

        if ($id === null) {
            $this->memberError = $this->memberResults === [] ? 'Member tidak ditemukan' : 'Pilih member dari daftar';

            return;
        }

        $this->selectMember($id);
    }

    /** Buka form daftar member; nomor HP dari kolom cari ikut terisi. */
    public function openMemberRegistration(): void
    {
        abort_unless($this->memberProgramEnabled() && $this->user()->can('member.create'), 403);
        $this->newMemberPhone = Phone::normalize($this->memberSearch) !== null ? trim($this->memberSearch) : '';
        $this->showMembers = true;
    }

    public function clearMember(): void
    {
        $this->reset('memberId', 'memberName');
    }

    /** Daftar cepat member (nama + HP + persetujuan) lalu langsung dipasang ke keranjang. */
    public function registerMember(): void
    {
        abort_unless($this->memberProgramEnabled() && $this->user()->can('member.create'), 403);
        $this->memberError = null;

        try {
            $member = app(SaveMember::class)->handle(null, MemberData::fromArray([
                'name' => mb_substr(trim($this->newMemberName), 0, 100),
                'phone' => mb_substr(trim($this->newMemberPhone), 0, 30),
                'consent' => $this->newMemberConsent,
            ]), $this->outlet())['member'];
        } catch (BusinessException $e) {
            $this->memberError = $this->failed($e);

            return;
        }

        $this->toast("Member {$member->name} terdaftar");
        $this->selectMember($member->id);
    }

    /** Maks. keranjang ditahan per browser */
    public const MAX_HELD = 10;

    /**
     * Keranjang ditahan di browser ini (session per device): belum jadi order, belum memotong stok.
     *
     * @return list<array{id: string, label: string, order_id: string, cart: list<array<string, mixed>>, order_type: string, table_label: string, notes: string, discount_type: string, discount_value: string, member_id?: string|null, member_name?: string, total: string|null, items: int, held_at: string}>
     */
    public function heldCarts(): array
    {
        $held = session($this->heldKey(), []);

        return is_array($held) ? array_values($held) : [];
    }

    /** Tahan keranjang saat ini lalu kosongkan layar untuk pelanggan berikutnya. */
    public function hold(): void
    {
        if ($this->cart === [] || $this->billId !== null) {
            return;
        }

        $held = $this->heldCarts();

        if (count($held) >= self::MAX_HELD) {
            $this->toast('Maksimal '.self::MAX_HELD.' transaksi ditahan. Selesaikan salah satu dulu.', 'warning');

            return;
        }

        $number = count($held) + 1;
        $held[] = [
            'id' => (string) Str::uuid7(),
            'label' => filled(trim($this->holdLabel)) ? mb_substr(trim($this->holdLabel), 0, 30) : 'Pelanggan '.$number,
            'order_id' => $this->orderId,
            'cart' => $this->cart,
            'order_type' => $this->orderType,
            'table_label' => $this->tableLabel,
            'notes' => $this->notes,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'member_id' => $this->memberId,
            'member_name' => $this->memberName,
            'total' => $this->quote['totals']?->grandTotal,
            'items' => (int) array_sum(array_column($this->cart, 'qty')),
            'held_at' => now()->toIso8601String(),
        ];
        session()->put($this->heldKey(), $held);

        $label = $held[array_key_last($held)]['label'];
        $this->resetOrder();
        $this->toast("Transaksi {$label} ditahan");
    }

    /** Lanjutkan keranjang ditahan; keranjang yang sedang terisi otomatis ditahan dulu (bertukar). */
    public function resume(string $heldId): void
    {
        $held = $this->heldCarts();
        $index = array_search($heldId, array_column($held, 'id'), true);

        if ($index === false) {
            return;
        }

        $entry = $held[$index];
        unset($held[$index]);
        session()->put($this->heldKey(), array_values($held));

        if ($this->cart !== [] && $this->billId === null) {
            $this->hold();
        }

        $this->resetOrder();
        // ID order ikut kembali: kirim ulang tetap satu transaksi (idempotency)
        $this->orderId = $entry['order_id'];
        $this->cart = $entry['cart'];
        $this->orderType = $entry['order_type'];
        $this->tableLabel = $entry['table_label'];
        $this->notes = $entry['notes'];
        $this->discountType = $entry['discount_type'];
        $this->discountValue = $entry['discount_value'];
        $this->holdLabel = str_starts_with($entry['label'], 'Pelanggan ') ? '' : $entry['label'];
        // Sesi lama (sebelum member) tidak punya kunci ini
        $this->memberId = $entry['member_id'] ?? null;
        $this->memberName = $entry['member_name'] ?? '';
        $this->toast("Transaksi {$entry['label']} dilanjutkan", 'info');
    }

    public function discardHeld(string $heldId): void
    {
        $held = $this->heldCarts();
        $entry = collect($held)->firstWhere('id', $heldId);

        if ($entry === null) {
            return;
        }

        session()->put($this->heldKey(), array_values(array_filter($held, fn (array $h): bool => $h['id'] !== $heldId)));
        $this->toast("Transaksi {$entry['label']} dihapus");
    }

    /** Notifikasi toast kanan atas (layout kasir), seperti notifikasi dashboard. */
    private function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('cashier-toast', message: $message, tone: $tone);
    }

    private function heldKey(): string
    {
        return 'cashier.held.'.WebCashier::device()->id;
    }

    // ---------- Open bill ----------

    /** Simpan keranjang sebagai open bill (baru atau memperbarui bill yang dibuka). */
    public function saveBill(): void
    {
        abort_unless($this->user()->can('order.create'), 403);

        if ($this->cart === []) {
            return;
        }

        // State Livewire bisa diubah dari browser: aturan bentuk data sama dengan API PUT /orders/{id}
        $input = $this->billInput();
        $request = new OpenBillRequest([], $input);
        $validator = Validator::make($input, $request->rules());

        try {
            if ($validator->fails()) {
                $this->toast($validator->errors()->first(), 'warning');

                return;
            }

            $result = app(SaveOpenBill::class)->handle($this->user(), WebCashier::device(), $this->orderId, OpenBillData::fromArray($input));
        } catch (BusinessException $e) {
            $this->toast($this->failed($e), 'warning');

            return;
        } finally {
            // PIN approver tidak boleh tertinggal di snapshot Livewire (terlihat di DOM)
            $this->approverPin = '';
        }

        $label = $result['order']->table_label ?? $result['order']->order_number;
        $this->resetOrder();
        $this->toast("Open bill {$label} disimpan");
        unset($this->openBills);
    }

    /** Buka open bill ke keranjang untuk ditambah item atau dilunasi. */
    public function loadBill(string $orderId): void
    {
        $order = VisibleOrders::query($this->user())->where('status', OrderStatus::Open)
            ->with('items.options')->find($orderId);

        if ($order === null) {
            $this->toast('Open bill tidak ditemukan atau sudah ditutup', 'warning');

            return;
        }

        $this->resetOrder();
        $this->billId = $order->id;
        $this->orderId = $order->id;
        $this->billPaid = $order->paid_total;
        $this->orderType = $order->order_type->value;
        $this->tableLabel = (string) $order->table_label;
        $this->notes = (string) $order->notes;
        $this->memberId = $order->member_id;
        $this->memberName = (string) $order->member_name;
        $this->discountType = $order->discount_type->value ?? '';
        $this->discountValue = $order->discount_value ?? '';
        $this->cart = $order->items->sortBy('sort_order')->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'name' => $item->product_name,
            'qty' => $item->qty,
            'option_ids' => $item->options->pluck('option_id')->filter()->values()->all(),
            'option_names' => $item->options->pluck('option_name')->values()->all(),
            'notes' => $item->notes,
            'discount' => $item->discount,
        ])->values()->all();
        $this->showBills = false;
    }

    public function newOrder(): void
    {
        $this->reset('completedOrderId', 'completedChange');
    }

    public function remaining(): string
    {
        $totals = $this->quote['totals'];
        $grand = $totals === null ? '0.00' : Money::sub($totals->grandTotal, $this->billPaid);
        $paid = '0.00';

        foreach ($this->payments as $p) {
            if (is_numeric(trim($p['amount'])) && (float) $p['amount'] > 0) {
                $paid = Money::add($paid, Money::of(trim($p['amount'])));
            }
        }

        return Money::sub($grand, $paid);
    }

    public function isCash(string $methodId): bool
    {
        return $this->catalog['payment_methods']->firstWhere('id', $methodId)?->category === PaymentCategory::Cash;
    }

    public function render(): View
    {
        return view('livewire.cashier.point-of-sale', [
            'outlet' => $this->outlet(),
            'canToggle' => $this->user()->can('product.toggle_available'),
        ]);
    }

    // ---------- Bantuan ----------

    private function openPicker(Product $product): void
    {
        $this->closePicker();
        $this->pickProductId = $product->id;

        foreach ($this->pickerGroups() as $group) {
            // Grup wajib pilih satu: opsi pertama yang tersedia langsung terpilih
            $first = $group->options->first(fn ($o): bool => (bool) $o->getAttribute('is_available'))?->id;
            $this->pickOptions[$group->id] = $group->max_select === 1
                ? ($group->min_select >= 1 && $first !== null ? $first : '')
                : [];
        }
    }

    private function resetOrder(): void
    {
        $this->reset('cart', 'orderType', 'tableLabel', 'notes', 'discountType', 'discountValue', 'paying', 'payments', 'payError',
            'billId', 'billPaid', 'needsApproval', 'approverId', 'approverPin', 'holdLabel', 'memberCode', 'memberPin',
            'memberId', 'memberName', 'showMembers', 'memberSearch', 'memberResults', 'newMemberName', 'newMemberPhone', 'newMemberConsent', 'memberError');
        $this->orderId = (string) Str::uuid7();
        // Total yang sudah dihitung di request ini (mis. saat menahan) jangan ikut ter-render
        unset($this->quote);
    }

    /** @return array<string, mixed> */
    private function billInput(): array
    {
        return [
            'order_type' => $this->orderType,
            'table_label' => $this->orderType === 'dine_in' && filled(trim($this->tableLabel)) ? mb_substr(trim($this->tableLabel), 0, 50) : null,
            'notes' => filled(trim($this->notes)) ? mb_substr(trim($this->notes), 0, 255) : null,
            // Selalu dikirim: null = member dilepas dari open bill
            'member_id' => $this->memberId,
            'discount' => $this->orderDiscountType() !== null ? ['type' => $this->discountType, 'value' => $this->orderDiscountValue()] : null,
            'items' => $this->checkoutItems(),
            ...$this->approvalInput(),
        ];
    }

    /** @return array{member_code?: string, member_pin?: string} */
    private function memberProofInput(): array
    {
        if (! $this->usesPoints()) {
            return [];
        }

        // Kode dari scanner bisa berisi "KODEMEMBER-123456": ambil 6 digit terakhir
        $code = preg_match('/(\d{6})$/', trim($this->memberCode), $match) === 1 ? $match[1] : null;

        return array_filter([
            'member_code' => $code,
            'member_pin' => preg_match('/^\d{4}$/', trim($this->memberPin)) === 1 ? trim($this->memberPin) : null,
        ]);
    }

    /** @return array{approver_user_id?: string, approver_pin?: string} */
    private function approvalInput(): array
    {
        if (! $this->needsApproval || $this->approverId === '' || ! preg_match('/^\d{6}$/', $this->approverPin)) {
            return [];
        }

        return ['approver_user_id' => $this->approverId, 'approver_pin' => $this->approverPin];
    }

    /**
     * Lunasi open bill: simpan isi terbaru (harga dihitung ulang), lalu tambah pembayaran.
     *
     * @param  array<string, mixed>  $data
     */
    private function payBill(array $data): Order
    {
        $device = WebCashier::device();
        $order = app(SaveOpenBill::class)->handle($this->user(), $device, $this->orderId, OpenBillData::fromArray($this->billInput()))['order'];

        return app(AddPayment::class)->handle($this->user(), $device, $order, CheckoutData::parsePayments($data['payments']),
            $data['member_code'] ?? null, $data['member_pin'] ?? null)['order'];
    }

    /** Pesan error; minta PIN approver bila server mensyaratkan approval. PIN tidak disimpan di state. */
    private function failed(BusinessException $e): string
    {
        $this->approverPin = '';

        if (in_array($e->errorCode, [ErrorCode::DiscountOverLimit->value, ErrorCode::ApprovalRequired->value, ErrorCode::InvalidPin->value, ErrorCode::SelfApprovalNotAllowed->value, ErrorCode::PinLocked->value], true)) {
            $this->needsApproval = true;
        }

        return self::describe($e);
    }

    /** @return list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}> */
    private function checkoutItems(): array
    {
        return array_map(fn (array $l): array => [
            'product_id' => $l['product_id'],
            'qty' => $l['qty'],
            'option_ids' => $l['option_ids'],
            'notes' => $l['notes'],
            'discount' => $l['discount'],
        ], $this->cart);
    }

    private function orderDiscountType(): ?DiscountType
    {
        $type = DiscountType::tryFrom($this->discountType);

        return $type !== null && is_numeric(trim($this->discountValue)) && (float) $this->discountValue > 0 ? $type : null;
    }

    private function orderDiscountValue(): string
    {
        return $this->orderDiscountType() !== null ? Money::of(trim($this->discountValue)) : '0.00';
    }

    private function product(string $id): ?Product
    {
        return $this->catalog['products']->firstWhere('id', $id);
    }

    private function outlet(): Outlet
    {
        return CurrentOutlet::getOrFail();
    }

    private function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }

    /** @return array{id: string, payment_method_id: string, amount: string, tendered: string, reference: string} */
    private static function paymentRow(string $methodId, string $amount): array
    {
        return ['id' => (string) Str::uuid7(), 'payment_method_id' => $methodId, 'amount' => $amount, 'tendered' => '', 'reference' => ''];
    }

    /** Pesan error bisnis + rincian per field (mis. "Croissant: stok tinggal 2"). */
    private static function describe(BusinessException $e): string
    {
        $details = collect(is_array($e->details) ? $e->details : [])->flatten()->filter(fn ($v): bool => is_string($v))->take(3)->implode(' · ');

        return $details !== '' && $details !== $e->getMessage() ? $e->getMessage().': '.$details : $e->getMessage();
    }
}
