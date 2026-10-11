<?php

declare(strict_types=1);

namespace App\Exports;

use App\Actions\Product\ImportProducts;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Template import produk (Q59): judul kolom + 2 contoh baris. */
final class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /** @return list<string> */
    public function headings(): array
    {
        return ImportProducts::COLUMNS;
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return [
            ['Es Kopi Susu', 'Kopi', 22000, 9000, 'KOPI-001', '', 'tidak', '', 'ya'],
            ['Croissant', 'Makanan', 24000, 12000, 'MKN-001', '8991234567890', 'ya', 20, 'ya'],
        ];
    }

    public function title(): string
    {
        return 'Produk';
    }
}
