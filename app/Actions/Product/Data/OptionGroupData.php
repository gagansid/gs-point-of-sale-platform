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
     * @param  list<array{id: string|null, name: string, price_delta: string, components: list<array{component_id: string, qty: int}>|null}>  $options
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
                'components' => self::components($option),
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

    /**
     * Bahan opsi (ADR 0020): API `components[]`, form dashboard satu pasang `component_id` + `component_qty`.
     * Null = tidak dikirim (tidak diubah).
     *
     * @param  array<string, mixed>  $option
     * @return list<array{component_id: string, qty: int}>|null
     */
    private static function components(array $option): ?array
    {
        if (array_key_exists('component_id', $option)) {
            $id = (string) ($option['component_id'] ?? '');
            $qty = (int) ($option['component_qty'] ?? 0);

            return $id !== '' && $qty > 0 ? [['component_id' => $id, 'qty' => $qty]] : [];
        }

        if (! array_key_exists('components', $option)) {
            return null;
        }

        $lines = [];
        foreach ((array) $option['components'] as $row) {
            $id = (string) ($row['component_id'] ?? '');
            $qty = (int) ($row['qty'] ?? 0);
            if ($id !== '' && $qty > 0) {
                $lines[] = ['component_id' => $id, 'qty' => $qty];
            }
        }

        return $lines;
    }
}
