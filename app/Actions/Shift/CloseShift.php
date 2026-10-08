<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Enums\ErrorCode;
use App\Enums\ShiftStatus;
use App\Exceptions\BusinessException;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shift\ShiftSummary;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menutup shift: hitung kas seharusnya (expected), bandingkan dengan kas aktual.
 *
 * - Tutup biasa (POST /shifts/{id}/close): hanya pembuka shift.
 * - Tutup paksa (POST /shifts/{id}/force-close): permission shift.force_close, wajib catatan.
 * Menutup shift yang sudah ditutup mengembalikan ringkasan yang sama (idempotent).
 */
final class CloseShift
{
    public function __construct(private readonly ShiftSummary $summary) {}

    /**
     * @return array{shift: Shift, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Shift $shift, string $actualCash, ?string $note, bool $force = false): array
    {
        $result = DB::transaction(function () use ($actor, $shift, $actualCash, $note, $force): array {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                return ['shift' => $locked, 'replayed' => true];
            }

            if (! $force && $locked->opened_by !== $actor->id) {
                throw BusinessException::of(ErrorCode::Forbidden, 'Shift ini dibuka karyawan lain. Gunakan tutup paksa');
            }

            $expected = $this->summary->for($locked)['expected_cash'];

            $locked->forceFill([
                'status' => $force ? ShiftStatus::ForceClosed : ShiftStatus::Closed,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'difference' => Money::sub($actualCash, $expected),
                'close_note' => $note,
                'open_device_key' => null,
            ])->save();

            return ['shift' => $locked, 'replayed' => false];
        });

        if (! $result['replayed']) {
            Log::info($force ? 'Shift ditutup paksa' : 'Shift ditutup', [
                'shift_id' => $shift->id,
                'user_id' => $actor->id,
                'difference' => $result['shift']->difference,
            ]);
        }

        return $result;
    }
}
