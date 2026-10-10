<?php

declare(strict_types=1);

namespace App\Actions\Product\Data;

use App\Support\Money;

/**
 * Grup opsi beserta daftar opsinya (urutan array = urutan tampil).
 */
final readonly class OptionGroupData
{
    /**
     * @param  list<array{id: string|null, name: string, price_delta: string}>  $options
     */
    public function __construct(
        public ?string $id,
        public string $name,
        public int $minSelect,
        public int $maxSelect,
        public bool $isActive,
        public array $options,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $options = [];
        foreach ($data['options'] ?? [] as $option) {
            $options[] = [
                'id' => filled($option['id'] ?? null) ? (string) $option['id'] : null,
                'name' => trim((string) $option['name']),
                'price_delta' => Money::of((string) ($option['price_delta'] ?? '0')),
            ];
        }

        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            name: trim((string) $data['name']),
            minSelect: (int) $data['min_select'],
            maxSelect: (int) $data['max_select'],
            isActive: (bool) ($data['is_active'] ?? true),
            options: $options,
        );
    }
}
