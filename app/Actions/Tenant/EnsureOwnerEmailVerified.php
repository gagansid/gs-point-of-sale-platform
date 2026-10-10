<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Tenant;

/**
 * Checkout & pembayaran baru boleh setelah email owner diverifikasi (ADR 0009, SPEC Q31).
 * Bisnis hasil daftar mandiri tetap bisa setup menu sebelum verifikasi.
 */
final class EnsureOwnerEmailVerified
{
    /**
     * @throws BusinessException EMAIL_NOT_VERIFIED
     */
    public function handle(Tenant $tenant): void
    {
        if ($tenant->needsOwnerEmailVerification()) {
            throw BusinessException::of(ErrorCode::EmailNotVerified);
        }
    }
}
