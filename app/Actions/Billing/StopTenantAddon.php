<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Services\Billing\TenantAddons;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

/**
 * Berhenti / lanjutkan add-on (ADR 0025 C2), oleh owner (Langganan) atau admin. Berhenti: tetap berlaku sampai akhir
 * masa aktif berbayar (tanpa pengembalian dana) dan tidak ikut perpanjangan; tanpa masa aktif berjalan = langsung
 * dilepas. Lanjutkan: batalkan jadwal berhenti.
 */
final class StopTenantAddon
{
    /** @throws BusinessException */
    public function handle(TenantAddon $row, bool $stop): ?TenantAddon
    {
        CreateInvoice::ensureSaas();

        $result = DB::transaction(function () use ($row, $stop): ?TenantAddon {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($row->tenant_id);
            $locked = TenantAddon::forTenant($tenant->id)->lockForUpdate()->find($row->id)
                ?? throw BusinessException::of(ErrorCode::NotFound);

            if ($stop) {
                TenantAddons::stop($locked, $tenant);

                return $locked->exists ? $locked : null;
            }

            if ($locked->status !== TenantAddon::CANCELLING) {
                return $locked;
            }

            $locked->forceFill(['status' => TenantAddon::ACTIVE, 'cancel_at' => null])->save();

            return $locked;
        });

        Features::flush();

        return $result;
    }
}
