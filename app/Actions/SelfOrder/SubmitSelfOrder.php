<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\QuoteOrder;
use App\Enums\ErrorCode;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\SelfOrder;
use App\Support\SelfOrderContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pelanggan mengirim pesanan dari HP (ADR 0014, Q70). Tidak ada order/stok yang dibuat: pesanan menunggu kasir.
 * Item & voucher divalidasi (katalog outlet, ADR 0011) dan totalnya dihitung server sebagai perkiraan; tagihan final
 * dihitung ulang saat diterima. Batas: 3 pesanan menunggu per meja, 10 pesanan/jam per IP, 50 item. `id` dari HP =
 * idempotency key (kirim ganda = pesanan yang sama).
 */
final class SubmitSelfOrder
{
    public const MAX_PENDING_PER_TABLE = 3;

    public const MAX_PER_IP_PER_HOUR = 10;

    public const MAX_ITEMS = 50;

    public function __construct(private readonly QuoteOrder $quote) {}

    /**
     * @param  array<string, mixed>  $data  id, customer_name, items[{product_id, qty, option_ids, notes}], notes, voucher_code
     * @return array{self_order: SelfOrder, replayed: bool}
     *
     * @throws ValidationException
     * @throws BusinessException SELF_ORDER_DISABLED, TOO_MANY_REQUESTS, VALIDATION_ERROR, VOUCHER_INVALID
     */
    public function handle(SelfOrderContext $context, array $data, ?Member $member, string $ip): array
    {
        $validated = Validator::make($data, self::rules(), [], [
            'customer_name' => 'nama', 'items' => 'pesanan', 'items.*.qty' => 'jumlah', 'notes' => 'catatan', 'voucher_code' => 'kode voucher',
        ])->validate();

        $existing = SelfOrder::query()->find($validated['id']);
        if ($existing !== null) {
            return $existing->dining_table_id === $context->table->id
                ? ['self_order' => $existing, 'replayed' => true]
                : throw BusinessException::of(ErrorCode::ValidationError, 'ID pesanan sudah dipakai', ['id' => ['ID pesanan sudah dipakai']]);
        }

        if (($reason = $context->unavailableReason()) !== null) {
            throw BusinessException::of(ErrorCode::SelfOrderDisabled, $reason);
        }

        $items = CheckoutData::parseItems($validated['items']);
        if (array_sum(array_column($items, 'qty')) > self::MAX_ITEMS) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.self::MAX_ITEMS.' item per pesanan', ['items' => ['Maksimal '.self::MAX_ITEMS.' item per pesanan']]);
        }

        $pending = SelfOrder::query()->where('dining_table_id', $context->table->id)->where('status', SelfOrderStatus::Pending)->count();
        if ($pending >= self::MAX_PENDING_PER_TABLE) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Masih ada '.$pending.' pesanan di meja ini yang menunggu kasir. Tunggu sebentar ya');
        }

        $key = 'self-order-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_IP_PER_HOUR)) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Terlalu banyak pesanan dari perangkat ini. Silakan pesan di kasir');
        }

        $voucher = filled($validated['voucher_code'] ?? null) ? trim((string) $validated['voucher_code']) : null;
        // Item, opsi, ketersediaan & voucher dicek sekarang agar pelanggan langsung tahu (VALIDATION_ERROR / VOUCHER_INVALID)
        $totals = $this->quote->handle($context->outlet, $items, null, '0.00', $member, new BenefitSelection(voucherCode: $voucher));

        $selfOrder = new SelfOrder([
            'outlet_id' => $context->outlet->id,
            'dining_table_id' => $context->table->id,
            'member_id' => $member?->id,
            'customer_name' => trim((string) $validated['customer_name']),
            'items' => array_map(fn (array $i): array => ['product_id' => $i['product_id'], 'qty' => $i['qty'], 'option_ids' => $i['option_ids'], 'notes' => $i['notes']], $items),
            'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            'voucher_code' => $voucher,
            'estimated_total' => $totals->grandTotal,
            'ip_address' => $ip,
        ]);
        $selfOrder->id = $validated['id'];

        try {
            $selfOrder->save();
        } catch (UniqueConstraintViolationException) {
            return ['self_order' => SelfOrder::query()->findOrFail($validated['id']), 'replayed' => true];
        }

        RateLimiter::hit($key, 3600);

        return ['self_order' => $selfOrder, 'replayed' => false];
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@]/'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.option_ids' => ['sometimes', 'array', 'max:20'],
            'items.*.option_ids.*' => ['uuid'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'voucher_code' => ['nullable', 'string', 'max:30'],
        ];
    }
}
