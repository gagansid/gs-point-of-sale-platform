<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Enums\ErrorCode;
use App\Enums\OpeningCashSource;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Koreksi kas awal shift yang sedang berjalan (ADR 0017), mis. sisa laci shift sebelumnya ternyata berbeda.
 * Hanya dari perangkat shift itu; izin shift.edit_opening_cash atau PIN approval (approver ≠ pelaku); alasan wajib.
 * Nilai semula disimpan di carried_cash (koreksi pertama), pelaku & approver tercatat untuk panel Audit.
 */
final class CorrectOpeningCash
{
    public function __construct(private readonly VerifyApproval $verifyApproval) {}

    /** @throws BusinessException */
    public function handle(User $actor, Shift $shift, Device $device, string $openingCash, string $note, ?ApprovalData $approval = null): Shift
    {
        if (! $shift->isOpen()) {
            throw BusinessException::of(ErrorCode::ShiftNotOpen, 'Shift sudah ditutup');
        }

        if ($shift->device_id !== $device->id) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Kas awal hanya bisa dikoreksi dari perangkat shift ini');
        }

        $approverId = $this->verifyApproval->handle($actor, $shift->outlet_id, 'shift.edit_opening_cash', $approval);

        $shift = DB::transaction(function () use ($actor, $shift, $openingCash, $note, $approverId): Shift {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $locked->isOpen()) {
                throw BusinessException::of(ErrorCode::ShiftNotOpen, 'Shift sudah ditutup');
            }

            if (Money::compare($openingCash, $locked->opening_cash) === 0) {
                return $locked;
            }

            $locked->forceFill([
                // Nilai semula hanya dicatat sekali (carry-over sudah tersimpan sejak dibuka)
                'carried_cash' => $locked->carried_cash ?? $locked->opening_cash,
                'opening_cash' => $openingCash,
                'opening_cash_source' => OpeningCashSource::Adjusted,
                'opening_cash_note' => $note,
                'opening_cash_adjusted_by' => $actor->id,
                'opening_cash_approved_by' => $approverId,
                'opening_cash_adjusted_at' => now(),
            ])->save();

            return $locked;
        });

        Log::info('Kas awal shift dikoreksi', [
            'shift_id' => $shift->id,
            'user_id' => $actor->id,
            'approved_by' => $approverId,
            'opening_cash' => $shift->opening_cash,
            'carried_cash' => $shift->carried_cash,
        ]);

        return $shift;
    }
}
