<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Pembaca file import produk (Q59): baris 1 = judul kolom (lihat ImportProducts::COLUMNS), dibaca sebagai
 * array per baris. Rumus tidak dihitung (nilai mentah), jadi sel diawali "=" ditolak validasi.
 */
final class ProductImportSheet implements WithHeadingRow {}
