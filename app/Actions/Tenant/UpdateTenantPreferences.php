<?php

declare(strict_types=1);

namespace App\Actions\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Simpan sebagian preferensi bisnis (Q128) — digabung dengan nilai lama (kunci lain tidak hilang). Nilai sudah
 * divalidasi pemanggil (form Pengaturan → Tampilan / aksi Sembunyikan panduan). Baris tenant dikunci agar dua
 * penyimpanan bersamaan tidak saling menimpa.
 */
final class UpdateTenantPreferences
{
    /** @param  array<string, mixed>  $values  null = kembali ke bawaan (kunci dihapus) */
    public function handle(Tenant $tenant, array $values): Tenant
    {
        return DB::transaction(function () use ($tenant, $values): Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $preferences = $locked->preferences ?? [];

            foreach ($values as $key => $value) {
                if ($value === null) {
                    unset($preferences[$key]);
                } else {
                    $preferences[$key] = $value;
                }
            }

            $locked->forceFill(['preferences' => $preferences === [] ? null : $preferences])->save();
            Log::info('Preferensi bisnis diubah', ['tenant_id' => $locked->id, 'keys' => array_keys($values)]);

            return $locked;
        });
    }
}
