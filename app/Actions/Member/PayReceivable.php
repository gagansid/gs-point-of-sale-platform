<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Actions\Order\CheckoutOrder;
use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use App\Enums\ErrorCode;
use App\Enums\PaymentCategory;
use App\Enums\ReceivableEntryType;
use App\Exceptions\BusinessException;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Member;
use App\Models\PaymentMethod;
use App\Models\ReceivableEntry;
use App\Models\User;
use App\Services\Member\ReceivableLedger;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pelunasan kasbon member (ADR 0023, Q106): nominal ≤ saldo, metode bayar aktif (bukan poin/online/kasbon). Dari
 * perangkat (aplikasi/kasir web) tunai = kas masuk "Pelunasan kasbon" di shift terbuka; dari dashboard dicatat tanpa
 * laci. `id` dari klien = idempotency key.
 */
final class PayReceivable
{
    public function __construct(private readonly ReceivableLedger $ledger) {}

    /**
     * @return array{entry: ReceivableEntry, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Member $member, ?Device $device, string $id, string $amount, string $paymentMethodId, ?string $note = null): array
    {
        if (($existing = $this->existing($id, $member)) !== null) {
            return ['entry' => $existing, 'replayed' => true];
        }

        $method = PaymentMethod::query()->whereKey($paymentMethodId)->where('is_active', true)->first();
        if ($method === null || $method->category->isSystem()) {
            throw self::invalid('payment_method_id', 'Metode bayar tidak bisa dipakai untuk pelunasan.');
        }

        if (! Money::isPositive($amount)) {
            throw self::invalid('amount', 'Nominal harus lebih dari 0.');
        }

        $shift = $device !== null && $method->category === PaymentCategory::Cash ? CheckoutOrder::openShiftOf($device) : null;

        try {
            $entry = DB::transaction(function () use ($actor, $member, $device, $id, $amount, $method, $note, $shift): ReceivableEntry {
                $locked = $this->ledger->lock($member);

                if (Money::compare($amount, $locked->receivable_balance) > 0) {
                    throw self::invalid('amount', 'Pelunasan melebihi kasbon '.Money::format($locked->receivable_balance).'.');
                }

                $movementId = null;
                if ($shift !== null) {
                    $movement = new CashMovement([
                        'outlet_id' => $shift->outlet_id, 'shift_id' => $shift->id, 'user_id' => $actor->id,
                        'type' => CashMovementType::In, 'reason' => CashMovementReason::ReceivablePayment,
                        'amount' => $amount, 'note' => 'Kasbon '.$locked->name,
                    ]);
                    $movement->id = (string) Str::uuid7();
                    $movement->save();
                    $movementId = $movement->id;
                }

                return $this->ledger->record($locked, ReceivableEntryType::Payment, Money::sub('0.00', $amount), [
                    'outlet_id' => $device?->outlet_id,
                    'payment_method_id' => $method->id,
                    'cash_movement_id' => $movementId,
                    'user_id' => $actor->id,
                    'note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
                ], $id);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->existing($id, $member) ?? throw BusinessException::of(ErrorCode::NotFound);

            return ['entry' => $existing, 'replayed' => true];
        }

        Log::info('Kasbon dilunasi', ['member_id' => $member->id, 'entry_id' => $entry->id, 'amount' => $amount, 'user_id' => $actor->id]);

        return ['entry' => $entry, 'replayed' => false];
    }

    /**
     * Pelunasan dengan ID ini milik member yang sama; ID milik member/tenant lain → 404.
     *
     * @phpstan-impure
     */
    private function existing(string $id, Member $member): ?ReceivableEntry
    {
        return Idempotency::existing(ReceivableEntry::class, $id, fn (ReceivableEntry $e): bool => $e->member_id === $member->id);
    }

    private static function invalid(string $field, string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);
    }
}
