<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Enums\ErrorCode;
use App\Enums\ShiftStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shift\ShiftSummary;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menutup shift: hitung kas seharusnya (expected), bandingkan dengan kas aktual, catat setoran (ADR 0017).
 *
 * - Tutup biasa (POST /shifts/{id}/close): siapa saja yang bertugas di perangkat shift itu (shift milik
 *   perangkat, bukan milik pembuka).
 * - Tutup paksa (POST /shifts/{id}/force-close): permission shift.force_close, wajib catatan; untuk menutup
 *   dari perangkat lain / dashboard (perangkat rusak, lupa tutup).
 * - closing_float = kas aktual − disetor, menjadi kas awal shift berikutnya di perangkat yang sama.
 * Menutup shift yang sudah ditutup mengembalikan ringkasan yang sama (idempotent).
 */
final class CloseShift
{
    public function __construct(
        private readonly ShiftSummary $summary,
        private readonly RecordShiftOperator $operators,
    ) {}

    /**
     * @param  Device|null  $device  perangkat pelaku (null = dashboard); tutup biasa wajib dari perangkat shift
     * @param  string|null  $cashDeposited  null = bawaan: kas aktual − modal laci tetap (bila ada), selain itu 0
     * @return array{shift: Shift, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Shift $shift, ?Device $device, string $actualCash, ?string $cashDeposited, ?string $note, bool $force = false): array
    {
        $result = DB::transaction(function () use ($actor, $shift, $device, $actualCash, $cashDeposited, $note, $force): array {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                return ['shift' => $locked, 'replayed' => true];
            }

            if (! $force && $locked->device_id !== $device?->id) {
                throw BusinessException::of(ErrorCode::Forbidden, 'Shift ini terbuka di perangkat lain. Gunakan tutup paksa');
            }

            $deposited = $cashDeposited ?? self::defaultDeposit($actualCash, Outlet::query()->find($locked->outlet_id)?->cash_float);

            if (Money::compare($deposited, $actualCash) > 0) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Disetor melebihi kas dihitung', [
                    'cash_deposited' => ['Disetor tidak boleh lebih dari kas dihitung.'],
                ]);
            }

            $expected = $this->summary->for($locked)['expected_cash'];
            $closedAt = now();

            $locked->forceFill([
                'status' => $force ? ShiftStatus::ForceClosed : ShiftStatus::Closed,
                'closed_by' => $actor->id,
                'closed_at' => $closedAt,
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'difference' => Money::sub($actualCash, $expected),
                'cash_deposited' => $deposited,
                'closing_float' => Money::sub($actualCash, $deposited),
                'close_note' => $note,
                'open_device_key' => null,
            ])->save();

            $this->operators->end($locked, $closedAt);

            return ['shift' => $locked, 'replayed' => false];
        });

        if (! $result['replayed']) {
            Log::info($force ? 'Shift ditutup paksa' : 'Shift ditutup', [
                'shift_id' => $shift->id,
                'user_id' => $actor->id,
                'difference' => $result['shift']->difference,
                'cash_deposited' => $result['shift']->cash_deposited,
            ]);
        }

        return $result;
    }

    /** Disetor bawaan: kas dihitung − modal laci tetap (minimal 0); tanpa modal tetap = 0 (semua tinggal di laci). */
    public static function defaultDeposit(string $actualCash, ?string $cashFloat): string
    {
        if ($cashFloat === null) {
            return '0.00';
        }

        return Money::max('0.00', Money::sub($actualCash, Money::fromDb($cashFloat)));
    }
}
