<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Template menu awal untuk bisnis baru (onboarding ADR 0009). Harga contoh dalam Rupiah;
 * owner bisa mengubah atau menghapusnya setelah diterapkan.
 *
 * Struktur: kategori → produk [nama, harga, grup opsi], grup opsi → [min, maks, opsi [nama, tambahan]].
 */
final class MenuTemplates
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     option_groups: array<string, array{min: int, max: int, options: list<array{0: string, 1: int}>}>,
     *     categories: array<string, list<array{0: string, 1: int, 2?: list<string>}>>
     * }>
     */
    public static function all(): array
    {
        return [
            'cafe' => [
                'label' => 'Kafe / kedai kopi',
                'description' => 'Kopi, non kopi, makanan ringan + opsi ukuran & gula',
                'option_groups' => [
                    'Ukuran' => ['min' => 1, 'max' => 1, 'options' => [['Regular', 0], ['Large', 5000]]],
                    'Gula' => ['min' => 1, 'max' => 1, 'options' => [['Normal', 0], ['Less sugar', 0], ['Tanpa gula', 0]]],
                ],
                'categories' => [
                    'Kopi' => [
                        ['Americano', 18000, ['Ukuran', 'Gula']],
                        ['Kopi Susu', 22000, ['Ukuran', 'Gula']],
                        ['Caffe Latte', 25000, ['Ukuran', 'Gula']],
                        ['Cappuccino', 25000, ['Ukuran', 'Gula']],
                    ],
                    'Non Kopi' => [
                        ['Matcha Latte', 28000, ['Ukuran', 'Gula']],
                        ['Coklat', 24000, ['Ukuran', 'Gula']],
                        ['Teh Tarik', 18000, ['Ukuran', 'Gula']],
                    ],
                    'Makanan' => [
                        ['Croissant', 22000],
                        ['Roti Bakar', 18000],
                        ['Kentang Goreng', 20000],
                    ],
                ],
            ],
            'warung' => [
                'label' => 'Warung makan',
                'description' => 'Makanan, minuman, camilan + opsi level pedas & es/panas',
                'option_groups' => [
                    'Level pedas' => ['min' => 1, 'max' => 1, 'options' => [['Tidak pedas', 0], ['Sedang', 0], ['Pedas', 0]]],
                    'Penyajian' => ['min' => 1, 'max' => 1, 'options' => [['Panas', 0], ['Es', 1000]]],
                ],
                'categories' => [
                    'Makanan' => [
                        ['Nasi Goreng', 15000, ['Level pedas']],
                        ['Mie Goreng', 13000, ['Level pedas']],
                        ['Ayam Geprek', 17000, ['Level pedas']],
                        ['Nasi Telur', 10000],
                    ],
                    'Minuman' => [
                        ['Teh Manis', 4000, ['Penyajian']],
                        ['Jeruk', 5000, ['Penyajian']],
                        ['Kopi Hitam', 4000, ['Penyajian']],
                    ],
                    'Camilan' => [
                        ['Gorengan', 2000],
                        ['Kerupuk', 2000],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, string> kunci => label untuk pilihan di form */
    public static function options(): array
    {
        return array_map(fn (array $template): string => $template['label'].' — '.$template['description'], self::all());
    }
}
