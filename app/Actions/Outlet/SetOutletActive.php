<?php

declare(strict_types=1);

namespace App\Actions\Outlet;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Menonaktifkan / mengaktifkan outlet (ADR 0010). Outlet tidak pernah dihapus: riwayat order,
 * shift, dan laporan tetap ada. Outlet nonaktif tidak bisa dipakai bertransaksi (perangkatnya
 * ditolak) dan tidak muncul di pemilih outlet.
 */
final class SetOutletActive
{
    /**
     * @throws BusinessException
     */
    public function handle(Outlet $outlet, bool $active): Outlet
    {
        DB::transaction(function () use ($outlet, $active): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($outlet->tenant_id);
            $locked = Outlet::query()->lockForUpdate()->findOrFail($outlet->id);

            if ($locked->is_active === $active) {
                return;
            }

            if ($active) {
                CreateOutlet::ensureWithinLimit($tenant);
            } elseif (Outlet::query()->active()->whereKeyNot($locked->id)->doesntExist()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Bisnis wajib memiliki minimal satu outlet aktif', [
                    'is_active' => ['Outlet aktif terakhir tidak bisa dinonaktifkan'],
                ]);
            }

            $locked->forceFill(['is_active' => $active])->save();
        });

        return $outlet->refresh();
    }
}
