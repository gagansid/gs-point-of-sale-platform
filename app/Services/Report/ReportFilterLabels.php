<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;

/**
 * Teks filter aktif ("Kasir: Budi, Sari") untuk badge halaman Laporan dan kop export.
 * Nama dicari lewat query tenant-scoped: ID tenant lain tidak menghasilkan nama.
 */
final class ReportFilterLabels
{
    /** @return list<string> */
    public static function describe(ReportFilters $filters): array
    {
        $pills = [];

        if ($filters->orderType !== null) {
            $pills[] = 'Tipe: '.$filters->orderType->getLabel();
        }

        $named = [
            'Metode' => [$filters->paymentMethodIds, fn (array $ids) => PaymentMethod::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')],
            'Kasir' => [$filters->userIds, fn (array $ids) => User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')],
            'Kategori' => [$filters->categoryIds, fn (array $ids) => Category::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')],
            'Produk' => [$filters->productIds, fn (array $ids) => Product::query()->withTrashed()->whereIn('id', $ids)->orderBy('name')->pluck('name')],
        ];

        foreach ($named as $label => [$ids, $names]) {
            if ($ids !== []) {
                $pills[] = $label.': '.$names($ids)->implode(', ');
            }
        }

        if ($filters->shiftIds !== []) {
            $pills[] = 'Shift: '.CurrentOutlet::scope(Shift::query())->whereIn('id', $filters->shiftIds)->count().' dipilih';
        }

        return $pills;
    }
}
