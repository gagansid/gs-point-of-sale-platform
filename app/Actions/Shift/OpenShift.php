<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Enums\ShiftStatus;
use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Membuka shift di device (POST /shifts). Satu shift terbuka per device: bila sudah ada,
 * shift itu yang dikembalikan (idempotent), termasuk saat dua request datang bersamaan.
 */
final class OpenShift
{
    /**
     * @return array{shift: Shift, replayed: bool}
     */
    public function handle(User $actor, Device $device, string $openingCash): array
    {
        if (($existing = $this->openShiftOf($device)) !== null) {
            return ['shift' => $existing, 'replayed' => true];
        }

        try {
            $shift = DB::transaction(function () use ($actor, $device, $openingCash): Shift {
                $shift = new Shift([
                    'outlet_id' => $device->outlet_id,
                    'device_id' => $device->id,
                    'opened_by' => $actor->id,
                    'opening_cash' => $openingCash,
                    'status' => ShiftStatus::Open,
                    'opened_at' => now(),
                ]);
                // Penjaga di tingkat database: unik per device selama terbuka
                $shift->open_device_key = $device->id;
                $shift->save();

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

        Log::info('Shift dibuka', ['shift_id' => $shift->id, 'device_id' => $device->id, 'user_id' => $actor->id]);

        return ['shift' => $shift, 'replayed' => false];
    }

    private function openShiftOf(Device $device): ?Shift
    {
        return Shift::query()->where('open_device_key', $device->id)->first();
    }
}
