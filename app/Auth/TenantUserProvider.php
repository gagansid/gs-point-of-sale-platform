<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider auth untuk users tenant (driver "tenant_users").
 *
 * Saat login/membaca session, tenant belum diketahui sehingga TenantScope (fail-closed) harus
 * dilepas khusus untuk mencari user. Hanya user aktif yang bisa login atau mempertahankan sesi:
 * menonaktifkan karyawan langsung memutus sesinya.
 */
final class TenantUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)
            ->withoutGlobalScope(TenantScope::class)
            ->where('is_active', true);
    }
}
