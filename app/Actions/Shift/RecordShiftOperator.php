<?php

declare(strict_types=1);

namespace App\Actions\Shift;

use App\Models\Device;
use App\Models\Scopes\OutletScope;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Log operator shift (ADR 0017): siapa memegang kasir di perangkat, dari jam berapa sampai jam berapa.
 * Dipanggil saat shift dibuka, login PIN/email di perangkat terdaftar, dan buka kunci kasir web.
 * Operator yang sama berturut-turut tidak membuat baris baru.
 */
final class RecordShiftOperator
{
    /** Operator baru untuk shift yang sedang terbuka di perangkat; tanpa shift terbuka tidak mencatat apa pun. */
    public function handle(Device $device, User $user): ?ShiftOperator
    {
        // Mode hanya-baca (ADR 0009): login tetap boleh untuk melihat laporan, log tidak ditulis
        if (TenantContext::isReadOnly()) {
            return null;
        }

        return DB::transaction(function () use ($device, $user): ?ShiftOperator {
            // Perangkat sudah diverifikasi pemanggil; saat login PIN konteks outlet belum terisi
            $shift = Shift::query()->withoutGlobalScope(OutletScope::class)
                ->where('open_device_key', $device->id)->lockForUpdate()->first();

            return $shift !== null ? $this->switchTo($shift, $user) : null;
        });
    }

    /** Ganti operator shift (pemanggil sudah mengunci baris shift di transaksi yang sama). */
    public function switchTo(Shift $shift, User $user, ?CarbonInterface $at = null): ShiftOperator
    {
        $current = self::current($shift);

        if ($current !== null && $current->user_id === $user->id) {
            return $current;
        }

        $at ??= now();
        $current?->forceFill(['ended_at' => $at])->save();

        $operator = new ShiftOperator(['shift_id' => $shift->id, 'user_id' => $user->id, 'started_at' => $at]);
        $operator->tenant_id = $shift->tenant_id;
        $operator->save();

        return $operator;
    }

    /** Akhiri operator yang sedang bertugas (shift ditutup). */
    public function end(Shift $shift, ?CarbonInterface $at = null): void
    {
        ShiftOperator::query()->where('shift_id', $shift->id)->whereNull('ended_at')->update(['ended_at' => $at ?? now()]);
    }

    public static function current(Shift $shift): ?ShiftOperator
    {
        return ShiftOperator::query()->where('shift_id', $shift->id)->whereNull('ended_at')->latest('started_at')->first();
    }
}
