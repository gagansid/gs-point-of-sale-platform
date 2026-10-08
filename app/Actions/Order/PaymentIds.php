<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Payment;

/**
 * ID pembayaran dari aplikasi tidak boleh sudah dipakai (lintas tenant, tanpa membocorkan data).
 */
final class PaymentIds
{
    /**
     * @param  list<array{id: string}>  $payments
     *
     * @throws BusinessException
     */
    public static function ensureUnused(array $payments): void
    {
        $ids = array_column($payments, 'id');
        $used = Payment::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('id')->all();

        if ($used === []) {
            return;
        }

        $errors = [];
        foreach ($ids as $index => $id) {
            if (in_array($id, $used, true)) {
                $errors["payments.{$index}.id"] = ['ID pembayaran sudah dipakai'];
            }
        }

        throw BusinessException::of(ErrorCode::ValidationError, details: $errors);
    }
}
