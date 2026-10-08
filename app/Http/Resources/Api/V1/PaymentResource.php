<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Payment;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_method_id' => $this->payment_method_id,
            'payment_method_name' => $this->whenLoaded('method', fn () => $this->method->name),
            'category' => $this->category->value,
            'amount' => $this->amount,
            'tendered' => $this->tendered,
            'change' => $this->change,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'created_at' => Iso::dateTime($this->created_at),
        ];
    }
}
