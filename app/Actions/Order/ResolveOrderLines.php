<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Data\ResolvedLine;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Option;
use App\Models\Product;
use App\Support\Money;

/**
 * Mencocokkan item kiriman aplikasi dengan katalog server: produk aktif & tersedia, opsi milik
 * grup opsi produk, dan jumlah pilihan per grup sesuai min/max. Harga SELALU dari database.
 * Semua pelanggaran dikumpulkan lalu dilempar sekali sebagai VALIDATION_ERROR per field.
 */
final class ResolveOrderLines
{
    /**
     * @param  list<array{product_id: string, qty: int, option_ids: list<string>, notes: string|null, discount: string}>  $items
     * @return list<ResolvedLine>
     *
     * @throws BusinessException
     */
    public function handle(array $items): array
    {
        $products = Product::query()
            ->with('optionGroups.options')
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        $errors = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);

            if ($product === null || ! $product->is_active) {
                $errors["items.{$index}.product_id"] = ['Produk tidak tersedia'];

                continue;
            }

            if (! $product->is_available) {
                $errors["items.{$index}.product_id"] = ["{$product->name} sedang habis"];

                continue;
            }

            /** @var array<string, Option> $allowed opsi yang sah untuk produk ini */
            $allowed = [];
            foreach ($product->optionGroups as $group) {
                foreach ($group->options as $option) {
                    $allowed[$option->id] = $option;
                }
            }

            $selected = [];
            foreach ($item['option_ids'] as $optionId) {
                if (! isset($allowed[$optionId])) {
                    $errors["items.{$index}.option_ids"] = ["Opsi tidak valid untuk {$product->name}"];

                    continue 2;
                }
                $selected[] = $allowed[$optionId];
            }

            foreach ($product->optionGroups as $group) {
                $count = collect($selected)->where('option_group_id', $group->id)->count();

                if ($count < $group->min_select || $count > $group->max_select) {
                    $errors["items.{$index}.option_ids"] = [$group->min_select === $group->max_select
                        ? "Pilih {$group->min_select} {$group->name} untuk {$product->name}"
                        : "Pilih {$group->min_select}–{$group->max_select} {$group->name} untuk {$product->name}"];

                    continue 2;
                }
            }

            $lines[] = new ResolvedLine(
                product: $product,
                qty: $item['qty'],
                options: $selected,
                optionsTotal: Money::add(...array_map(fn (Option $o): string => $o->price_delta, $selected)),
                discount: $item['discount'],
                notes: $item['notes'],
            );
        }

        if ($errors !== []) {
            throw BusinessException::of(ErrorCode::ValidationError, details: $errors);
        }

        return $lines;
    }
}
