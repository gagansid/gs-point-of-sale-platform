<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductIndexRequest extends FormRequest
{
    /** Kolom yang boleh dipakai `sort` (allowlist — nilai lain ditolak, bukan disisipkan ke SQL). */
    public const SORTABLE = ['name', 'price', 'stock_qty', 'sort_order', 'created_at', 'updated_at'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            // `price` = naik, `-price` = turun
            'sort' => ['nullable', 'string', Rule::in([...self::SORTABLE, ...array_map(fn (string $column): string => '-'.$column, self::SORTABLE)])],
        ];
    }

    /**
     * Kolom & arah urutan dari `sort`, atau null bila tidak dikirim.
     *
     * @return array{0: string, 1: 'asc'|'desc'}|null
     */
    public function sort(): ?array
    {
        $sort = $this->string('sort')->toString();

        if ($sort === '') {
            return null;
        }

        return str_starts_with($sort, '-') ? [substr($sort, 1), 'desc'] : [$sort, 'asc'];
    }
}
