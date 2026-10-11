<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengecekan ID dari client sebagai idempotency key (docs/standards/api/idempotency.md).
 */
final class Idempotency
{
    /**
     * Data lama milik tenant aktif bila ID sudah dipakai; null bila ID baru.
     * ID milik tenant lain → 404 (jangan bocorkan keberadaannya).
     *
     * Pencarian sengaja melepas semua global scope (termasuk OutletScope) agar ID yang sudah dipakai
     * tetap terdeteksi. Karena itu pemanggil yang datanya per outlet/per user WAJIB mengirim
     * $canAccess: data yang tidak boleh diakses pelaku juga dijawab 404 (GSPOS-SEC-001).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  (Closure(TModel): bool)|null  $canAccess
     * @return TModel|null
     *
     * @throws BusinessException
     */
    public static function existing(string $model, ?string $id, ?Closure $canAccess = null): ?Model
    {
        if ($id === null) {
            return null;
        }

        $found = $model::query()->withoutGlobalScopes()->find($id);

        if ($found === null) {
            return null;
        }

        $trashed = method_exists($found, 'trashed') && $found->trashed();

        if ($found->getAttribute('tenant_id') !== TenantContext::idOrFail() || $trashed) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        if ($canAccess !== null && ! $canAccess($found)) {
            throw BusinessException::of(ErrorCode::NotFound);
        }

        return $found;
    }
}
