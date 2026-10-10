<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Product;

use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

final class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $ignoreId = $product instanceof Product ? $product->id : null;

        return [
            'id' => ['sometimes', 'uuid'],
            'category_id' => ['nullable', 'uuid', self::tenantExists('categories')],
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:50', self::tenantUnique('sku', $ignoreId)],
            'barcode' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/', self::tenantUnique('barcode', $ignoreId)],
            'price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'cost_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'track_stock' => ['sometimes', 'boolean'],
            // Stok awal hanya saat membuat; perubahan berikutnya lewat POST /products/{id}/stock
            'stock_qty' => [$ignoreId === null ? 'sometimes' : 'prohibited', 'integer', 'min:-1000000', 'max:1000000'],
            // Batas peringatan "stok menipis"; null = tanpa peringatan
            'min_stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_favorite' => ['sometimes', 'boolean'],
            'option_group_ids' => ['sometimes', 'array', 'max:20'],
            'option_group_ids.*' => ['uuid', 'distinct', self::tenantExists('option_groups')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama produk',
            'option_group_ids' => 'grup opsi',
            'option_group_ids.*' => 'grup opsi',
            'stock_qty' => 'stok',
            'track_stock' => 'lacak stok',
            'min_stock' => 'stok minimum',
        ];
    }

    /** Data milik tenant aktif dan belum dihapus. */
    private static function tenantExists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', TenantContext::id())->whereNull('deleted_at');
    }

    /** Unik di antara produk tenant aktif yang belum dihapus. */
    private static function tenantUnique(string $column, ?string $ignoreId): Unique
    {
        return Rule::unique('products', $column)
            ->where('tenant_id', TenantContext::id())
            ->whereNull('deleted_at')
            ->ignore($ignoreId);
    }
}
