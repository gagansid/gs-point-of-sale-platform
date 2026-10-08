<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Karyawan yang boleh login PIN di sebuah device: aktif, punya PIN, dan terdaftar di outlet
 * device (atau berlaku untuk semua outlet). Dipakai layar pilih nama & login PIN.
 */
final class PinUsers
{
    /** @return Builder<User> */
    public static function query(Device $device): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotNull('pin')
            ->where(fn (Builder $q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $device->outlet_id));
    }

    /** @return Collection<int, User> */
    public function handle(Device $device): Collection
    {
        return self::query($device)->orderBy('name')->get(['id', 'name', 'role']);
    }
}
