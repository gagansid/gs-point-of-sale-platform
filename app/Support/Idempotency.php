<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
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
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     *
     * @throws BusinessException
     */
    public static function existing(string $model, ?string $id): ?Model
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

        return $found;
    }
}
