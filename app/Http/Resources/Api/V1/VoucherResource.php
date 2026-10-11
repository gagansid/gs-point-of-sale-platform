<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Voucher;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Voucher untuk kasir (ADR 0013, M7): cukup untuk pratinjau potongan; aturan pakai tetap dicek server saat checkout.
 *
 * @mixin Voucher
 */
final class VoucherResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'source' => $this->source->value,
            'type' => $this->type->value,
            'value' => $this->value,
            'max_discount' => $this->max_discount,
            'min_spend' => $this->min_spend,
            'member_only' => $this->member_only,
            'ends_at' => Iso::dateTime($this->ends_at),
        ];
    }
}
