<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter tambahan laporan (beranda dashboard & API /reports): tipe order, metode bayar, kasir.
 * ID asing (tenant lain) aman: query order sudah dibatasi TenantScope + outlet, hasilnya kosong.
 */
final readonly class ReportFilters
{
    /**
     * @param  list<string>  $paymentMethodIds
     * @param  list<string>  $userIds
     */
    public function __construct(
        public ?OrderType $orderType = null,
        public array $paymentMethodIds = [],
        public array $userIds = [],
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
            is_string($type) ? OrderType::tryFrom($type) : null,
            self::uuids($input['payment_method_ids'] ?? []),
            self::uuids($input['user_ids'] ?? []),
        );
    }

    public function isEmpty(): bool
    {
        return $this->orderType === null && $this->paymentMethodIds === [] && $this->userIds === [];
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
            // Order yang punya minimal satu pembayaran lunas dengan metode terpilih
            ->when($this->paymentMethodIds !== [], fn (Builder $q) => $q->whereIn($q->qualifyColumn('id'), Payment::query()
                ->select('order_id')
                ->whereIn('payment_method_id', $this->paymentMethodIds)
                ->whereIn('status', [PaymentStatus::Paid, PaymentStatus::Voided])));
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
