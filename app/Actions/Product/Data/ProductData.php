<?php

declare(strict_types=1);

namespace App\Actions\Product\Data;

use App\Support\Money;

/**
 * Data produk dari API maupun form dashboard (field sudah tervalidasi).
 */
final readonly class ProductData
{
    /**
     * @param  list<string>  $optionGroupIds  urutan = urutan tampil di aplikasi
     */
    public function __construct(
        public ?string $id,
        public ?string $categoryId,
        public string $name,
        public ?string $sku,
        public ?string $barcode,
        public string $price,
        public ?string $costPrice,
        public bool $trackStock,
        public int $initialStock,
        public bool $isActive,
        public ?string $imagePath,
        public array $optionGroupIds,
        // Bedakan "gambar tidak dikirim" (API) dari "gambar dikosongkan" (form dashboard)
        public bool $imagePathProvided = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cost = $data['cost_price'] ?? null;

        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            categoryId: filled($data['category_id'] ?? null) ? (string) $data['category_id'] : null,
            name: trim((string) $data['name']),
            sku: filled($data['sku'] ?? null) ? trim((string) $data['sku']) : null,
            barcode: filled($data['barcode'] ?? null) ? trim((string) $data['barcode']) : null,
            price: Money::of((string) $data['price']),
            costPrice: filled($cost) ? Money::of((string) $cost) : null,
            trackStock: (bool) ($data['track_stock'] ?? false),
            initialStock: (int) ($data['stock_qty'] ?? 0),
            isActive: (bool) ($data['is_active'] ?? true),
            imagePath: filled($data['image_path'] ?? null) ? (string) $data['image_path'] : null,
            optionGroupIds: array_values(array_map('strval', $data['option_group_ids'] ?? [])),
            imagePathProvided: array_key_exists('image_path', $data),
        );
    }
}
