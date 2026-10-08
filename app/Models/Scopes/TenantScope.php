<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Membatasi semua query ke tenant aktif.
 *
 * Fail-closed (ADR 0005): tanpa tenant context, query tidak mengembalikan data apa pun.
 * Kode yang memang lintas tenant (panel /admin, command sistem) wajib melepas scope secara
 * eksplisit lewat Model::allTenants() atau Model::forTenant($id).
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        // Kolom diberi nama tabel agar tidak ambigu saat join
        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
