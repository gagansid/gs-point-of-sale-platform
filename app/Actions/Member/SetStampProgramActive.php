<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\StampProgram;

/**
 * Aktif/nonaktifkan program stempel (izin member.settings). Nonaktif: tidak ada stempel baru & tidak bisa ditukar;
 * saldo stempel member tetap tersimpan dan berlaku lagi saat diaktifkan.
 */
final class SetStampProgramActive
{
    public function handle(StampProgram $program, bool $active): StampProgram
    {
        $program->forceFill(['is_active' => $active])->save();

        return $program;
    }
}
