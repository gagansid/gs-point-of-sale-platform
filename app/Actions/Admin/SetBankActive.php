<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Bank;
use Illuminate\Support\Facades\Log;

/** Aktifkan/nonaktifkan bank. Nonaktif: tidak bisa dipilih untuk rekening baru; rekening yang ada tetap tampil. */
final class SetBankActive
{
    public function handle(Bank $bank, bool $active): Bank
    {
        $bank->forceFill(['is_active' => $active])->save();
        Log::info('Status bank diubah', ['bank_id' => $bank->id, 'is_active' => $active]);

        return $bank;
    }
}
