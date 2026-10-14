<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Enums\ErrorCode;
use App\Enums\OpeningCashSource;
use App\Enums\ShiftStatus;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Membuka shift di device (POST /shifts). Satu shift terbuka per device: bila sudah ada,
 * shift itu yang dikembalikan (idempotent), termasuk saat dua request datang bersamaan.
 *
 * Kas awal (ADR 0017):
 * - perangkat yang shift terakhirnya mencatat sisa laci (closing_float) → carry-over, kasir tidak mengetik angka;
 * - angka lain dari carry-over = koreksi: izin shift.edit_opening_cash atau PIN approval, alasan wajib;
 * - perangkat tanpa shift sebelumnya (atau shift lama sebelum ADR 0017) → kas awal wajib diketik.
 */
final class OpenShift
{
    public function __construct(
        private readonly VerifyApproval $verifyApproval,
        private readonly RecordShiftOperator $operators,
    ) {}

    /**
     * @param  string|null  $openingCash  null = pakai carry-over
     * @param  string|null  $note  alasan bila kas awal berbeda dari carry-over
     * @return array{shift: Shift, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, ?string $openingCash, ?string $note = null, ?ApprovalData $approval = null): array
    {
        if (($existing = $this->openShiftOf($device)) !== null) {
            $this->operators->handle($device, $actor);

            return ['shift' => $existing, 'replayed' => true];
        }

        $previous = self::previousShift($device);
        $carried = $previous?->closing_float;
        $opening = self::resolveOpening($carried, $openingCash);
        $adjusted = $carried !== null && Money::compare($opening, $carried) !== 0;
        $approverId = null;

        if ($adjusted) {
            if (blank($note)) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Alasan wajib diisi bila kas awal berbeda dari sisa laci', [
                    'opening_cash_note' => ['Alasan wajib diisi bila kas awal berbeda dari sisa laci shift sebelumnya.'],
                ]);
            }

            // Diverifikasi di luar transaksi: percobaan PIN salah tetap tercatat
            $approverId = $this->verifyApproval->handle($actor, $device->outlet_id, 'shift.edit_opening_cash', $approval);
        }

        try {
            $shift = DB::transaction(function () use ($actor, $device, $opening, $carried, $previous, $adjusted, $approverId, $note): Shift {
                $shift = new Shift([
                    'outlet_id' => $device->outlet_id,
                    'device_id' => $device->id,
                    'opened_by' => $actor->id,
                    'opening_cash' => $opening,
                    'status' => ShiftStatus::Open,
                    'opened_at' => now(),
                ]);
                $shift->forceFill([
                    'opening_cash_source' => match (true) {
                        $carried === null => OpeningCashSource::Manual,
                        $adjusted => OpeningCashSource::Adjusted,
                        default => OpeningCashSource::CarryOver,
                    },
                    'carried_cash' => $carried,
                    'previous_shift_id' => $previous?->id,
                    'opening_cash_note' => $adjusted ? $note : null,
                    'opening_cash_adjusted_by' => $adjusted ? $actor->id : null,
                    'opening_cash_approved_by' => $approverId,
                    'opening_cash_adjusted_at' => $adjusted ? now() : null,
                ]);
                // Penjaga di tingkat database: unik per device selama terbuka
                $shift->open_device_key = $device->id;
                $shift->save();

                $this->operators->switchTo($shift, $actor);

                return $shift;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Request lain membuka shift di device ini pada saat bersamaan: kembalikan shift itu
            $concurrent = Shift::query()->where('open_device_key', $device->id)->first();

            if ($concurrent === null) {
                throw $e;
            }

            return ['shift' => $concurrent, 'replayed' => true];
        }

        Log::info('Shift dibuka', [
            'shift_id' => $shift->id,
            'device_id' => $device->id,
            'user_id' => $actor->id,
            'opening_cash_source' => $shift->opening_cash_source->value,
        ]);

        return ['shift' => $shift, 'replayed' => false];
    }

    /** Shift terakhir yang ditutup di perangkat ini (sumber carry-over). */
    public static function previousShift(Device $device): ?Shift
    {
        return Shift::query()->where('device_id', $device->id)
            ->whereIn('status', [ShiftStatus::Closed, ShiftStatus::ForceClosed])
            ->latest('closed_at')->first();
    }

    /** Kas awal yang akan dipakai bila shift dibuka sekarang tanpa koreksi; null = wajib diketik. */
    public static function carryOverFor(Device $device): ?string
    {
        $carried = self::previousShift($device)?->closing_float;

        return $carried !== null ? Money::fromDb($carried) : null;
    }

    /** @throws BusinessException */
    private static function resolveOpening(?string $carried, ?string $openingCash): string
    {
        if ($openingCash !== null) {
            return $openingCash;
        }

        if ($carried === null) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Kas awal wajib diisi', [
                'opening_cash' => ['Kas awal wajib diisi untuk shift pertama di perangkat ini.'],
            ]);
        }

        return Money::fromDb($carried);
    }

    private function openShiftOf(Device $device): ?Shift
    {
        return Shift::query()->where('open_device_key', $device->id)->first();
    }
}
