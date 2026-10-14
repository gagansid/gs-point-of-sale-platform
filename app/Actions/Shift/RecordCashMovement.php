<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Shift\Data\CashMovementData;
use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shift\ShiftSummary;
use App\Support\Idempotency;
use App\Support\Money;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kas masuk/keluar laci di shift yang terbuka di perangkat (ADR 0017), mis. beli galon atau tukar kembalian.
 *
 * - ID dari klien = idempotency key (kiriman ulang → data lama).
 * - Kas keluar kasir di atas outlets.cash_out_approval_limit butuh PIN approval (shift.cash_out_over_limit).
 * - Kas keluar tidak boleh melebihi kas yang seharusnya ada di laci.
 * - Tidak bisa diubah/dihapus; koreksi dengan catatan lawan.
 */
final class RecordCashMovement
{
    public function __construct(
        private readonly VerifyApproval $verifyApproval,
        private readonly ShiftSummary $summary,
    ) {}

    /**
     * @return array{movement: CashMovement, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, CashMovementData $data, ?ApprovalData $approval = null): array
    {
        if (($existing = Idempotency::existing(CashMovement::class, $data->id, self::replayableAt($device))) !== null) {
            return ['movement' => $existing, 'replayed' => true];
        }

        self::validate($data);

        $shift = CheckoutOrder::openShiftOf($device);
        $outlet = Outlet::query()->findOrFail($shift->outlet_id);

        $approverId = null;
        if ($data->type === CashMovementType::Out && self::needsApproval($data->amount, $outlet->cash_out_approval_limit)) {
            // Diverifikasi di luar transaksi: percobaan PIN salah tetap tercatat
            $approverId = $this->verifyApproval->handle($actor, $outlet->id, 'shift.cash_out_over_limit', $approval, details: [
                'limit' => Money::fromDb($outlet->cash_out_approval_limit),
            ]);
        }

        try {
            $movement = DB::transaction(function () use ($actor, $shift, $data, $approverId): CashMovement {
                // Kunci shift: masih terbuka & saldo laci tidak berubah oleh kas keluar lain bersamaan
                $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

                if (! $locked->isOpen()) {
                    throw BusinessException::of(ErrorCode::ShiftNotOpen);
                }

                if ($data->type === CashMovementType::Out) {
                    $inDrawer = $this->summary->for($locked)['expected_cash'];

                    if (Money::compare($data->amount, $inDrawer) > 0) {
                        throw BusinessException::of(ErrorCode::ValidationError, 'Kas keluar melebihi uang di laci', [
                            'amount' => ['Kas keluar melebihi uang yang seharusnya ada di laci ('.Money::format($inDrawer).').'],
                        ]);
                    }
                }

                $movement = new CashMovement([
                    'outlet_id' => $locked->outlet_id,
                    'shift_id' => $locked->id,
                    'user_id' => $actor->id,
                    'type' => $data->type,
                    'reason' => $data->reason,
                    'amount' => $data->amount,
                    'note' => $data->note,
                    'approved_by' => $approverId,
                ]);
                $movement->id = $data->id;
                $movement->save();

                return $movement;
            });
        } catch (UniqueConstraintViolationException) {
            // Request kembar lolos pengecekan awal bersamaan; primary key menjadi penjaga terakhir
            $existing = Idempotency::existing(CashMovement::class, $data->id, self::replayableAt($device))
                ?? throw BusinessException::of(ErrorCode::NotFound);

            return ['movement' => $existing, 'replayed' => true];
        }

        Log::info('Kas '.($data->type === CashMovementType::In ? 'masuk' : 'keluar').' dicatat', [
            'cash_movement_id' => $movement->id,
            'shift_id' => $movement->shift_id,
            'user_id' => $actor->id,
            'amount' => $movement->amount,
            'approved_by' => $approverId,
        ]);

        return ['movement' => $movement, 'replayed' => false];
    }

    /** Kas keluar di atas batas butuh approval; batas null = tidak pernah, 0 = selalu. */
    public static function needsApproval(string $amount, ?string $limit): bool
    {
        return $limit !== null && Money::compare($amount, Money::fromDb($limit)) > 0;
    }

    /** @throws BusinessException */
    private static function validate(CashMovementData $data): void
    {
        if (! $data->reason->allows($data->type)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Alasan tidak sesuai jenis kas', [
                'reason' => ['"'.$data->reason->getLabel().'" hanya untuk kas keluar.'],
            ]);
        }

        if ($data->reason === CashMovementReason::Other && $data->note === null) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Catatan wajib diisi', [
                'note' => ['Catatan wajib diisi untuk alasan "Lainnya".'],
            ]);
        }

        if (! Money::isPositive($data->amount)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nominal harus lebih dari 0', ['amount' => ['Nominal harus lebih dari 0.']]);
        }
    }

    /**
     * Kiriman ulang hanya mengembalikan catatan di outlet perangkat ini; selain itu 404 (GSPOS-SEC-001).
     *
     * @return Closure(CashMovement): bool
     */
    private static function replayableAt(Device $device): Closure
    {
        return fn (CashMovement $movement): bool => $movement->outlet_id === $device->outlet_id;
    }
}
