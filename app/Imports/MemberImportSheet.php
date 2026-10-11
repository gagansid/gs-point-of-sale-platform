<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Pembaca file import member (ADR 0013, M7): baris 1 = judul kolom (ImportMembers::COLUMNS), dibaca sebagai array
 * per baris. Rumus tidak dihitung (nilai mentah), jadi sel diawali "=" ditolak validasi.
 */
final class MemberImportSheet implements WithHeadingRow {}
