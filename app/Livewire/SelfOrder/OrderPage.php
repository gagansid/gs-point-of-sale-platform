<?php

declare(strict_types=1);

namespace App\Livewire\SelfOrder;

use App\Actions\Catalog\GetCatalog;
use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Order\QuoteOrder;
use App\Actions\Payment\StartOnlinePayment;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Member;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\PaymentGatewaySetting;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Services\Order\CalculationResult;
use App\Support\SelfOrderContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Halaman pesan dari meja di HP (ADR 0014, Q70): menu outlet, keranjang, kirim pesanan, dan status (polling).
 * Tanpa login (nama wajib) atau sebagai member (sesi halaman member). Harga hanya pratinjau dari server; tagihan
 * final dihitung ulang saat kasir menerima. Pesanan milik sesi browser ini disimpan di session per meja.
 *
 * Request update Livewire tidak melewati middleware route: konteks meja dibaca ulang dari token di boot().
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

    // menu | cart | status
    public string $view = 'menu';

    public string $category = '';

    /** @var list<array{product_id: string, name: string, qty: int, option_ids: list<string>, option_names: list<string>, notes: string|null}> */
    public array $cart = [];

    public string $customerName = '';

    public string $notes = '';

    public string $voucherCode = '';

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
        $this->view = $this->myOrders->isNotEmpty() ? 'status' : 'menu';
    }

    /** @return array{categories: Collection<int, Category>, products: Collection<int, Product>, option_groups: Collection<int, OptionGroup>} */
    #[Computed]
    public function catalog(): array
    {
        $catalog = app(GetCatalog::class)->handle($this->context->outlet);

        return ['categories' => $catalog['categories']->toBase(), 'products' => $catalog['products']->toBase(), 'option_groups' => $catalog['option_groups']->toBase()];
    }

    /** @return Collection<int, Product> */
    public function visibleProducts(): Collection
    {
        return $this->catalog['products']->when($this->category !== '', fn (Collection $p) => $p->where('category_id', $this->category))->values();
    }

    /** @return array{totals: CalculationResult|null, error: string|null} */
    #[Computed]
    public function quote(): array
    {
        if ($this->cart === []) {
            return ['totals' => null, 'error' => null];
        }

        try {
            return ['totals' => app(QuoteOrder::class)->handle($this->context->outlet, $this->items(), null, '0.00', $this->member(),
                new BenefitSelection(voucherCode: filled(trim($this->voucherCode)) ? trim($this->voucherCode) : null)), 'error' => null];
        } catch (BusinessException $e) {
            return ['totals' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return Collection<int, SelfOrder> pesanan sesi ini di meja ini, terbaru dulu */
    #[Computed]
    public function myOrders(): Collection
    {
        $ids = (array) session()->get($this->sessionKey(), []);

        return SelfOrder::query()->with(['order:id,order_number,status,grand_total,paid_total'])->whereIn('id', $ids)
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
        $this->reset('pickOptions', 'pickNotes', 'pickError');
        $this->pickQty = 1;
        $this->pickProductId = $product->id;

        foreach ($this->pickerGroups() as $group) {
            $this->pickOptions[$group->id] = $group->max_select === 1 ? '' : [];
        }
    }

    public function savePick(): void
    {
        $product = $this->catalog['products']->firstWhere('id', $this->pickProductId);

        if (! $product instanceof Product) {
            $this->closePicker();

            return;
        }

        $optionIds = [];
        $names = [];

        foreach ($this->pickerGroups() as $group) {
            $chosen = array_values(array_filter((array) ($this->pickOptions[$group->id] ?? []), fn (string $id): bool => $id !== ''));

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

        $this->cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'qty' => max(1, min(SubmitSelfOrder::MAX_ITEMS, $this->pickQty)),
            'option_ids' => $optionIds,
            'option_names' => $names,
            'notes' => filled(trim($this->pickNotes)) ? mb_substr(trim($this->pickNotes), 0, 255) : null,
        ];
        $this->closePicker();
        unset($this->quote);
    }

    public function closePicker(): void
    {
        $this->reset('pickProductId', 'pickOptions', 'pickQty', 'pickNotes', 'pickError');
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
        if (isset($this->cart[$index]) && $this->cart[$index]['qty'] < SubmitSelfOrder::MAX_ITEMS) {
            $this->cart[$index]['qty']++;
            unset($this->quote);
        }
    }

    public function decrement(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $this->cart[$index]['qty']--;
        if ($this->cart[$index]['qty'] < 1) {
            array_splice($this->cart, $index, 1);
        }
        unset($this->quote);
    }

    public function show(string $view): void
    {
        $this->view = in_array($view, ['menu', 'cart', 'status'], true) ? $view : 'menu';
        $this->error = null;
    }

    // ---------- Kirim ----------

    public function submit(): void
    {
        $this->error = null;

        try {
            $result = app(SubmitSelfOrder::class)->handle($this->context, [
                'id' => $this->submitId,
                'customer_name' => mb_substr(trim($this->customerName), 0, 100),
                'items' => $this->items(),
                'notes' => filled(trim($this->notes)) ? mb_substr(trim($this->notes), 0, 255) : null,
                'voucher_code' => filled(trim($this->voucherCode)) ? mb_substr(trim($this->voucherCode), 0, 30) : null,
            ], $this->member(), (string) request()->ip());
        } catch (ValidationException $e) {
            $this->error = $e->validator->errors()->first();

            return;
        } catch (BusinessException $e) {
            $this->error = $e->getMessage();

            return;
        }

        session()->put($this->sessionKey(), array_values(array_unique([...(array) session()->get($this->sessionKey(), []), $result['self_order']->id])));
        $this->reset('cart', 'notes', 'voucherCode');
        $this->submitId = (string) Str::uuid7();
        unset($this->myOrders, $this->quote);
        $this->view = 'status';
    }

    /** Bayar online (Midtrans Snap) pesanan yang sudah diterima; hanya pesanan milik sesi ini. */
    public function payOnline(string $selfOrderId): void
    {
        $selfOrder = $this->myOrders->firstWhere('id', $selfOrderId);
        $order = $selfOrder?->order_id !== null ? Order::query()->find($selfOrder->order_id) : null;

        if ($order === null) {
            return;
        }

        try {
            $transaction = app(StartOnlinePayment::class)->handle($order, $selfOrder->customer_name,
                route('self-order.table', ['token' => $this->context->table->qr_token]));
        } catch (BusinessException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirect((string) $transaction->redirect_url);
    }

    #[Computed]
    public function onlineReady(): bool
    {
        return PaymentGatewaySetting::current()->isReady();
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
            $order?->status === OrderStatus::AwaitingPayment => ['label' => 'Silakan bayar', 'tone' => 'info', 'note' => 'Pesanan diterima dengan nomor '.$order->order_number.'.'],
            $order?->status === OrderStatus::Completed => ['label' => 'Lunas', 'tone' => 'success', 'note' => 'Terima kasih! Pesananmu sedang disiapkan.'],
            $order?->status === OrderStatus::Expired => ['label' => 'Kedaluwarsa', 'tone' => 'muted', 'note' => 'Pesanan tidak dibayar dalam batas waktu.'],
            $order?->status === OrderStatus::Voided => ['label' => 'Dibatalkan', 'tone' => 'danger', 'note' => 'Pesanan dibatalkan kasir.'],
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
}
