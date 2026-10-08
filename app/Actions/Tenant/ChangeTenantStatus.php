<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Enums\TenantStatus;
use App\Models\Admin;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ChangeTenantStatus
{
    /**
     * Mengubah status tenant. Tenant suspended langsung ditolak di setiap request API
     * (403 TENANT_SUSPENDED) dan panel /dashboard.
     */
    public function handle(Tenant $tenant, TenantStatus $status, ?Admin $by = null): Tenant
    {
        $previous = $tenant->status;

        DB::transaction(fn () => $tenant->update(['status' => $status]));

        Log::warning('Status tenant diubah', [
            'tenant_id' => $tenant->id,
            'from' => $previous->value,
            'to' => $status->value,
            'admin_id' => $by?->id,
        ]);

        return $tenant;
    }
}
