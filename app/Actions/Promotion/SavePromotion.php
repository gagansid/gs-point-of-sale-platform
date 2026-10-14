<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\PromotionType;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buat/ubah promo otomatis (ADR 0022; izin promo.manage). ID produk/kategori/outlet disaring ke milik tenant ini;
 * aturan per jenis: diskon produk & minimal belanja butuh persen/nominal, beli X gratis Y butuh qty.
 */
final class SavePromotion
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?Promotion $promotion, array $data): Promotion
    {
        $v = Validator::make($data, self::rules(), [], [
            'name' => 'nama promo', 'type' => 'jenis', 'discount_type' => 'jenis potongan', 'discount_value' => 'besar potongan',
            'max_discount' => 'potongan maksimal', 'min_subtotal' => 'minimal belanja', 'buy_qty' => 'beli', 'get_qty' => 'gratis',
            'applies_to' => 'berlaku untuk', 'product_ids' => 'produk', 'category_ids' => 'kategori', 'days' => 'hari',
            'time_start' => 'jam mulai', 'time_end' => 'jam selesai', 'starts_on' => 'tanggal mulai', 'ends_on' => 'tanggal selesai',
        ])->validate();

        $type = PromotionType::from((string) $v['type']);
        $discountType = filled($v['discount_type'] ?? null) ? DiscountType::from((string) $v['discount_type']) : null;
        $fail = fn (string $field, string $message) => throw BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);

        if ($type !== PromotionType::BuyGet && ($discountType === null || ! Money::isPositive(Money::of((string) ($v['discount_value'] ?? '0'))))) {
            $fail('discount_value', 'Isi besar potongan.');
        }
        if ($discountType === DiscountType::Percent && Money::compare(Money::of((string) $v['discount_value']), '100.00') > 0) {
            $fail('discount_value', 'Potongan persen maksimal 100.');
        }
        if ($type === PromotionType::BuyGet && (empty($v['buy_qty']) || empty($v['get_qty']))) {
            $fail('buy_qty', 'Isi jumlah beli dan gratis.');
        }
        if (filled($v['time_start'] ?? null) !== filled($v['time_end'] ?? null)) {
            $fail('time_end', 'Isi jam mulai dan jam selesai, atau kosongkan keduanya.');
        }

        $appliesTo = $type === PromotionType::OrderDiscount ? Promotion::APPLIES_ALL : (string) ($v['applies_to'] ?? Promotion::APPLIES_ALL);
        $productIds = $appliesTo === Promotion::APPLIES_PRODUCTS ? Product::query()->whereIn('id', (array) ($v['product_ids'] ?? []))->pluck('id')->all() : null;
        $categoryIds = $appliesTo === Promotion::APPLIES_CATEGORIES ? Category::query()->whereIn('id', (array) ($v['category_ids'] ?? []))->pluck('id')->all() : null;
        if (($appliesTo === Promotion::APPLIES_PRODUCTS && $productIds === []) || ($appliesTo === Promotion::APPLIES_CATEGORIES && $categoryIds === [])) {
            $fail('applies_to', 'Pilih minimal satu produk atau kategori.');
        }
        $outletIds = filled($v['outlet_ids'] ?? null) ? Outlet::query()->whereIn('id', (array) $v['outlet_ids'])->pluck('id')->all() : null;

        return DB::transaction(function () use ($promotion, $v, $type, $discountType, $appliesTo, $productIds, $categoryIds, $outletIds): Promotion {
            $promotion ??= new Promotion;
            $promotion->fill([
                'name' => trim((string) $v['name']),
                'type' => $type,
                'discount_type' => $type === PromotionType::BuyGet ? null : $discountType,
                'discount_value' => $type === PromotionType::BuyGet ? '0.00' : Money::of((string) $v['discount_value']),
                'max_discount' => $discountType === DiscountType::Percent && filled($v['max_discount'] ?? null) ? Money::of((string) $v['max_discount']) : null,
                'min_subtotal' => $type === PromotionType::OrderDiscount && filled($v['min_subtotal'] ?? null) ? Money::of((string) $v['min_subtotal']) : null,
                'buy_qty' => $type === PromotionType::BuyGet ? (int) $v['buy_qty'] : null,
                'get_qty' => $type === PromotionType::BuyGet ? (int) $v['get_qty'] : null,
                'applies_to' => $appliesTo,
                'product_ids' => $productIds !== null ? array_values(array_map('strval', $productIds)) : null,
                'category_ids' => $categoryIds !== null ? array_values(array_map('strval', $categoryIds)) : null,
                'outlet_ids' => $outletIds !== null && $outletIds !== [] ? array_values(array_map('strval', $outletIds)) : null,
                'days' => filled($v['days'] ?? null) ? array_values(array_map('intval', (array) $v['days'])) : null,
                'time_start' => $v['time_start'] ?? null,
                'time_end' => $v['time_end'] ?? null,
                'starts_on' => $v['starts_on'] ?? null,
                'ends_on' => $v['ends_on'] ?? null,
                'is_active' => (bool) ($v['is_active'] ?? true),
            ])->save();

            return $promotion;
        });
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'max_discount' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'min_subtotal' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'buy_qty' => ['nullable', 'integer', 'min:1', 'max:100'],
            'get_qty' => ['nullable', 'integer', 'min:1', 'max:100'],
            'applies_to' => ['nullable', Rule::in([Promotion::APPLIES_ALL, Promotion::APPLIES_PRODUCTS, Promotion::APPLIES_CATEGORIES])],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['uuid'],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['uuid'],
            'outlet_ids' => ['nullable', 'array', 'max:100'],
            'outlet_ids.*' => ['uuid'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['integer', 'between:1,7'],
            'time_start' => ['nullable', 'date_format:H:i'],
            'time_end' => ['nullable', 'date_format:H:i'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
