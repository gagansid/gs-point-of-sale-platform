<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;

/**
 * Bar tombol halaman setelan, seragam dengan form Tambah/Ubah (form.md): menempel di bawah layar, rata kanan,
 * urutan [aksi tambahan] · Batal (muat ulang = buang perubahan) · Simpan (primary, ⌘S).
 */
final class SettingsFormActions
{
    /**
     * @param  string  $url  halaman ini (Batal = muat ulang tanpa menyimpan)
     * @param  Closure(): bool|null  $canEdit  null = selalu bisa menyimpan
     * @param  list<Action>  $extra  aksi abu-abu di kiri Batal, mis. "Tes koneksi"
     */
    public static function make(string $url, ?Closure $canEdit = null, array $extra = []): Actions
    {
        $visible = $canEdit ?? fn (): bool => true;

        // Bar rata kanan dirender terbalik (flex-row-reverse, sama dengan form Tambah): urutan DOM Simpan dulu
        return Actions::make([
            Action::make('save')->label('Simpan')->submit('save')->keyBindings(['mod+s'])->visible($visible),
            Action::make('cancel')->label('Batal')->color('gray')->url($url)->visible($visible),
            ...array_reverse($extra),
        ])->alignEnd();
    }
}
