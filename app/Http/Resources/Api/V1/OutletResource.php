<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Setelan outlet yang dipakai app untuk pratinjau total & struk (nilai final dihitung server).
 *
 * @mixin Outlet
 */
final class OutletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'tax_rate' => $this->tax_rate,
            'service_charge_rate' => $this->service_charge_rate,
            'tax_inclusive' => $this->tax_inclusive,
            'rounding' => $this->rounding,
            'receipt_header' => $this->receipt_header,
            'receipt_footer' => $this->receipt_footer,
            'receipt_show_rounding' => $this->receipt_show_rounding,
            'discount_limits' => $this->discount_limits,
        ];
    }
}
