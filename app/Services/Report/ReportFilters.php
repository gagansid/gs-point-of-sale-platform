<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Filter tambahan laporan (beranda, halaman Laporan, API /reports): tipe order, metode bayar,
 * kasir, kategori, produk, shift. ID asing (tenant/outlet lain) aman: query order sudah dibatasi
 * TenantScope + outlet, jadi hasilnya kosong (fail-closed, Q38).
 *
 * - Level order (apply): order yang memuat minimal satu item/pembayaran yang cocok.
 * - Level item (applyToItems): hanya baris item yang cocok (laporan produk, kategori, opsi).
 */
final readonly class ReportFilters
{
    /**
     * @param  list<string>  $paymentMethodIds
     * @param  list<string>  $userIds
     * @param  list<string>  $categoryIds
     * @param  list<string>  $productIds
     * @param  list<string>  $shiftIds
     */
    public function __construct(
        public ?OrderType $orderType = null,
        public array $paymentMethodIds = [],
        public array $userIds = [],
        public array $categoryIds = [],
        public array $productIds = [],
        public array $shiftIds = [],
    ) {}

    /**
     * Dari input bebas (state form / query string). Nilai tidak valid diabaikan.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $type = $input['order_type'] ?? null;

        return new self(
            // Form Filament mengirim enum, query string/API mengirim string
            $type instanceof OrderType ? $type : (is_string($type) ? OrderType::tryFrom($type) : null),
            self::uuids($input['payment_method_ids'] ?? []),
            self::uuids($input['user_ids'] ?? []),
            self::uuids($input['category_ids'] ?? []),
            self::uuids($input['product_ids'] ?? []),
            self::uuids($input['shift_ids'] ?? []),
        );
    }

    public function isEmpty(): bool
    {
        return $this->orderType === null && $this->paymentMethodIds === [] && $this->userIds === []
            && ! $this->hasItemFilter() && $this->shiftIds === [];
    }

    public function hasItemFilter(): bool
    {
        return $this->categoryIds !== [] || $this->productIds !== [];
    }

    /**
     * Membatasi query order sesuai filter.
     *
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    public function apply(Builder $orders): Builder
    {
        return $orders
            ->when($this->orderType !== null, fn (Builder $q) => $q->where($q->qualifyColumn('order_type'), $this->orderType))
            ->when($this->userIds !== [], fn (Builder $q) => $q->whereIn($q->qualifyColumn('user_id'), $this->userIds))
            ->when($this->shiftIds !== [], fn (Builder $q) => $q->whereIn($q->qualifyColumn('shift_id'), $this->shiftIds))
            // Order yang punya minimal satu pembayaran dengan metode terpilih
            ->when($this->paymentMethodIds !== [], fn (Builder $q) => $q->whereIn($q->qualifyColumn('id'), Payment::query()
                ->select('order_id')
                ->whereIn('payment_method_id', $this->paymentMethodIds)
                ->whereIn('status', [PaymentStatus::Paid, PaymentStatus::Voided])))
            // Order yang memuat minimal satu item produk/kategori terpilih
            ->when($this->hasItemFilter(), fn (Builder $q) => $q->whereIn($q->qualifyColumn('id'), $this->applyToItems(OrderItem::query())->select('order_items.order_id')));
    }

    /**
     * Membatasi baris order_items ke produk/kategori terpilih (kategori = kategori produk saat ini).
     *
     * @template TBuilder of Builder<OrderItem>|QueryBuilder
     *
     * @param  TBuilder  $items
     * @return TBuilder
     */
    public function applyToItems(Builder|QueryBuilder $items, string $table = 'order_items'): Builder|QueryBuilder
    {
        return $items
            ->when($this->productIds !== [], fn ($q) => $q->whereIn("{$table}.product_id", $this->productIds))
            ->when($this->categoryIds !== [], fn ($q) => $q->whereIn("{$table}.product_id", Product::query()
                ->withTrashed()
                ->select('id')
                ->whereIn('category_id', $this->categoryIds)));
    }

    /**
     * Filter aktif dalam bentuk array (untuk query string API, kunci export, ringkasan filter).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'order_type' => $this->orderType?->value,
            'payment_method_ids' => $this->paymentMethodIds,
            'user_ids' => $this->userIds,
            'category_ids' => $this->categoryIds,
            'product_ids' => $this->productIds,
            'shift_ids' => $this->shiftIds,
        ], fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /** @return list<string> */
    private static function uuids(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $values,
            fn (mixed $v): bool => is_string($v) && preg_match('/^[0-9a-fA-F-]{36}$/', $v) === 1,
        )));
    }
}
