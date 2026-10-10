<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Wajib dipakai setiap model bisnis yang punya kolom tenant_id.
 *
 * - Query otomatis dibatasi ke tenant aktif (TenantScope, fail-closed).
 * - tenant_id diisi otomatis saat create dan tidak boleh berbeda dari tenant aktif.
 * - tenant_id tidak boleh diubah setelah data dibuat.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $current = TenantContext::id();
            $model->tenant_id ??= $current;

            if ($model->tenant_id === null) {
                throw new LogicException(static::class.' dibuat tanpa tenant_id dan tanpa tenant context');
            }

            // Cegah penulisan ke tenant lain dari dalam konteks tenant (mis. tenant_id dari input)
            if ($current !== null && $model->tenant_id !== $current) {
                throw new LogicException(static::class.' tidak boleh dibuat untuk tenant lain');
            }
        });

        // Pengaman terakhir mode hanya-baca (ADR 0009): simpan/hapus data tenant ditolak walau
        // ada jalur yang lupa dicek di UI/permission. Query massal (->update()) tidak melewati event ini.
        $guardReadOnly = static function (): void {
            if (TenantContext::isReadOnly()) {
                throw BusinessException::of(ErrorCode::SubscriptionExpired);
            }
        };
        static::saving($guardReadOnly);
        static::deleting($guardReadOnly);

        static::updating(function (self $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException(static::class.': tenant_id tidak boleh diubah');
            }
        });
    }

    /**
     * Query lintas tenant: Model::allTenants(). HANYA untuk panel /admin dan command sistem.
     *
     * @param  Builder<Model>  $query
     */
    public function scopeAllTenants(Builder $query): void
    {
        $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Query untuk satu tenant tanpa mengubah tenant context: Model::forTenant($id) (panel /admin).
     *
     * @param  Builder<Model>  $query
     */
    public function scopeForTenant(Builder $query, string $tenantId): void
    {
        $query->withoutGlobalScope(TenantScope::class)->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
