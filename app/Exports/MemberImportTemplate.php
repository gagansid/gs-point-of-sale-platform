<?php

declare(strict_types=1);

namespace App\Exports;

use App\Actions\Member\ImportMembers;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** Template import member (ADR 0013, M7): judul kolom + 2 contoh baris; kolom nomor HP berformat teks. */
final class MemberImportTemplate implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    /** @return list<string> */
    public function headings(): array
    {
        return ImportMembers::COLUMNS;
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return [
            ['Rina Kusuma', '0812 3456 7890', 'rina@contoh.id', 17, 8, ''],
            ['Budi Santoso', '0857 1111 2222', '', '', '', 'Suka kopi susu'],
        ];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_TEXT];
    }

    public function title(): string
    {
        return 'Member';
    }
}
