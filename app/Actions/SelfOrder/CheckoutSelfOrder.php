<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Member\ResolveMemberPricing;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\PriceOrder;
use App\Actions\Order\ResolveOrderLines;
use App\Enums\ErrorCode;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Models\User;
use App\Support\SelfOrderContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bayar dulu tanpa terima kasir (ADR 0027 K2): keranjang HP langsung menjadi tagihan `awaiting_payment`. Ketersediaan,
 * harga, member & voucher dihitung ulang server tepat sebelum tagihan dibuat (OrderCalculator lewat PriceOrder). Order
 * masuk shift terbuka paling awal di outlet atas nama operator aktifnya; tanpa shift terbuka = outlet belum beroperasi.
 * Batas: 3 tagihan menunggu bayar per meja, 10 pesanan/jam per IP, 50 item. `id` dari HP = idempotency key.
 */
final class CheckoutSelfOrder
{
    public const MAX_AWAITING_PER_TABLE = 3;

    public const CLOSED_MESSAGE = 'Kasir belum buka, jadi pesanan belum bisa dibayar. Silakan pesan di kasir';

    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly ResolveMemberPricing $memberPricing,
        private readonly PriceOrder $priceOrder,
        private readonly SelfOrderBill $bill,
    ) {}

    /**
     * @param  array<string, mixed>  $data  id, customer_name (tamu), items[{product_id, qty, option_ids, notes}], notes, voucher_code
     * @return array{self_order: SelfOrder, order: Order, replayed: bool}
     *
     * @throws ValidationException
     * @throws BusinessException SELF_ORDER_DISABLED, UNAUTHENTICATED, TOO_MANY_REQUESTS, VALIDATION_ERROR, VOUCHER_INVALID
     */
    public function handle(SelfOrderContext $context, array $data, ?Member $member, string $ip): array
    {
        $rules = SubmitSelfOrder::rules();
        // Member: nama pemesan = nama member; tamu (program member nonaktif) wajib mengisi nama
        $rules['customer_name'] = [$member !== null ? 'nullable' : 'required', 'string', 'max:100', 'not_regex:/^[=+\-@]/'];
        $validated = Validator::make($data, $rules, [], [
            'customer_name' => 'nama', 'items' => 'pesanan', 'items.*.qty' => 'jumlah', 'notes' => 'catatan', 'voucher_code' => 'kode voucher',
        ])->validate();

        if (($replay = self::replay($context, (string) $validated['id'])) !== null) {
            return $replay;
        }

        if (($reason = $context->unavailableReason()) !== null) {
            throw BusinessException::of(ErrorCode::SelfOrderDisabled, $reason);
        }

        if ($member === null && $context->loginRequired()) {
            throw BusinessException::of(ErrorCode::Unauthenticated, 'Masuk sebagai member dulu untuk membayar');
        }

        $items = CheckoutData::parseItems($validated['items']);
        if (array_sum(array_column($items, 'qty')) > SubmitSelfOrder::MAX_ITEMS) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.SubmitSelfOrder::MAX_ITEMS.' item per pesanan', ['items' => ['Maksimal '.SubmitSelfOrder::MAX_ITEMS.' item per pesanan']]);
        }

        $awaiting = Order::query()->where('dining_table_id', $context->table->id)->where('channel', OrderChannel::SelfOrder)
            ->where('status', OrderStatus::AwaitingPayment)->count();
        if ($awaiting >= self::MAX_AWAITING_PER_TABLE) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Masih ada '.$awaiting.' pesanan di meja ini yang belum dibayar. Selesaikan pembayarannya dulu ya');
        }

        $key = 'self-order-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($key, SubmitSelfOrder::MAX_PER_IP_PER_HOUR)) {
            throw BusinessException::of(ErrorCode::TooManyRequests, 'Terlalu banyak pesanan dari perangkat ini. Silakan pesan di kasir');
        }

        $shift = self::openShift($context->outlet->id)
            ?? throw BusinessException::of(ErrorCode::SelfOrderDisabled, self::CLOSED_MESSAGE);
        $actor = self::operator($shift);

        $member = $member !== null && MemberSetting::current()->enabled && $member->isUsable() ? $member : null;
        $voucher = filled($validated['voucher_code'] ?? null) ? trim((string) $validated['voucher_code']) : null;
        // Ketersediaan, opsi, harga, member & voucher dihitung ulang sekarang (nilai dari HP diabaikan)
        $lines = $this->resolveLines->handle(array_map(fn (array $i): array => [...$i, 'discount' => '0.00'], $items), $context->outlet);
        $pricing = $this->memberPricing->handle($member, $context->outlet, $lines, new BenefitSelection(voucherCode: $voucher));
        $totals = $this->priceOrder->totals($context->outlet, $lines, null, '0.00', $pricing);

        try {
            $result = DB::transaction(function () use ($context, $validated, $items, $member, $voucher, $ip, $shift, $actor, $lines, $pricing, $totals): array {
                $selfOrder = new SelfOrder([
                    'outlet_id' => $context->outlet->id,
                    'dining_table_id' => $context->table->id,
                    'member_id' => $member?->id,
                    'customer_name' => trim((string) ($member->name ?? $validated['customer_name'])),
                    'items' => array_map(fn (array $i): array => ['product_id' => $i['product_id'], 'qty' => $i['qty'], 'option_ids' => $i['option_ids'], 'notes' => $i['notes']], $items),
                    'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
                    'voucher_code' => $voucher,
                    'estimated_total' => $totals->grandTotal,
                    'ip_address' => $ip,
                ]);
                $selfOrder->id = (string) $validated['id'];
                $selfOrder->save();
                $selfOrder->setRelation('diningTable', $context->table);

                $order = $this->bill->create($selfOrder, $context->outlet, $shift, $actor, $member, $lines, $pricing, $totals, handledBy: null);

                return ['self_order' => $selfOrder, 'order' => $order, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Kirim ganda bersamaan: request lain sudah membuat pesanan dengan id yang sama
            return self::replay($context, (string) $validated['id']) ?? throw BusinessException::of(ErrorCode::ValidationError, 'ID pesanan sudah dipakai');
        }

        RateLimiter::hit($key, 3600);

        return $result;
    }

    /** Shift terbuka paling awal di outlet (ADR 0027 §2): penampung tagihan pesanan meja. */
    public static function openShift(string $outletId): ?Shift
    {
        return Shift::query()->where('outlet_id', $outletId)->whereNotNull('open_device_key')->oldest('opened_at')->first();
    }

    /** Operator yang sedang bertugas di shift itu (ADR 0017), cadangan pembuka shift. */
    private static function operator(Shift $shift): User
    {
        $userId = ShiftOperator::query()->where('shift_id', $shift->id)->whereNull('ended_at')->latest('started_at')->value('user_id')
            ?? $shift->opened_by;

        return User::query()->findOrFail($userId);
    }

    /**
     * Pesanan dengan id yang sama: dikembalikan bila di meja yang sama (idempotent), selain itu ditolak.
     *
     * @return array{self_order: SelfOrder, order: Order, replayed: bool}|null
     *
     * @throws BusinessException
     *
     * @phpstan-impure membaca database: hasil bisa berubah di antara dua panggilan (request bersamaan)
     */
    private static function replay(SelfOrderContext $context, string $id): ?array
    {
        $existing = SelfOrder::query()->find($id);
        if ($existing === null) {
            return null;
        }

        $order = $existing->order_id !== null ? Order::query()->find($existing->order_id) : null;
        if ($existing->dining_table_id !== $context->table->id || $order === null) {
            throw BusinessException::of(ErrorCode::ValidationError, 'ID pesanan sudah dipakai', ['id' => ['ID pesanan sudah dipakai']]);
        }

        return ['self_order' => $existing, 'order' => $order, 'replayed' => true];
    }
}
