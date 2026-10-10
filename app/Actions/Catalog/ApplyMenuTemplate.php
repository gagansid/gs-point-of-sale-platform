<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveCategory;
use App\Actions\Product\SaveOptionGroup;
use App\Actions\Product\SaveProduct;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\MenuTemplates;
use Illuminate\Support\Facades\DB;

/**
 * Isi katalog awal dari template (Kafe/Warung) lewat Action yang sama dengan input manual.
 * Hanya untuk katalog kosong agar tidak menggandakan data bila diklik dua kali.
 */
final class ApplyMenuTemplate
{
    public function __construct(
        private readonly SaveCategory $saveCategory,
        private readonly SaveOptionGroup $saveOptionGroup,
        private readonly SaveProduct $saveProduct,
    ) {}

    /**
     * @return int jumlah produk yang dibuat
     *
     * @throws BusinessException
     */
    public function handle(string $templateKey, User $by): int
    {
        $template = MenuTemplates::all()[$templateKey] ?? throw BusinessException::of(ErrorCode::ValidationError, 'Template tidak dikenal', [
            'template' => ['Pilih template yang tersedia'],
        ]);

        // Template tanpa lacak stok; outlet hanya untuk baris stok awal produk
        $outlet = CurrentOutlet::getOrFail();

        return DB::transaction(function () use ($template, $by, $outlet): int {
            // Kunci baris kategori/produk: dua klik bersamaan tidak membuat katalog ganda
            if (Category::query()->lockForUpdate()->exists() || Product::query()->lockForUpdate()->exists()) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Template hanya bisa dipakai saat katalog masih kosong', [
                    'template' => ['Katalog sudah berisi kategori/produk'],
                ]);
            }

            $groupIds = [];
            foreach ($template['option_groups'] as $name => $group) {
                $groupIds[$name] = $this->saveOptionGroup->handle(null, OptionGroupData::fromArray([
                    'name' => $name,
                    'min_select' => $group['min'],
                    'max_select' => $group['max'],
                    'options' => array_map(fn (array $option): array => ['name' => $option[0], 'price_delta' => (string) $option[1]], $group['options']),
                ]))['group']->id;
            }

            $created = 0;
            foreach ($template['categories'] as $categoryName => $products) {
                $category = $this->saveCategory->handle(null, $categoryName)['category'];

                foreach ($products as $product) {
                    $this->saveProduct->handle(null, ProductData::fromArray([
                        'name' => $product[0],
                        'category_id' => $category->id,
                        'price' => (string) $product[1],
                        'option_group_ids' => array_map(fn (string $group): string => $groupIds[$group], $product[2] ?? []),
                    ]), $outlet, $by);
                    $created++;
                }
            }

            return $created;
        });
    }
}
