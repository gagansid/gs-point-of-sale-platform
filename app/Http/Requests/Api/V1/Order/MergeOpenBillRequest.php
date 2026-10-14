<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

/** Gabung open bill (ADR 0019). */
final class MergeOpenBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('order.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['source_order_id' => ['required', 'uuid']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['source_order_id' => 'open bill yang digabung'];
    }
}
