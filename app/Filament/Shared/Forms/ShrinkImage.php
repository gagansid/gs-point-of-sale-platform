<?php

declare(strict_types=1);

namespace App\Filament\Shared\Forms;

use Filament\Forms\Components\FileUpload;

/**
 * Foto dari kamera HP (3–6 MB, 4032×3024) diperkecil di browser sebelum diunggah (FilePond image transform), lalu
 * batas ukuran dicek server terhadap file hasil perkecilan. `maxSize()` Filament tidak dipakai karena FilePond
 * memvalidasinya pada file asli, sebelum diperkecil, sehingga foto kamera selalu ditolak. File non-gambar (PDF)
 * tidak diubah dan tetap kena batas server.
 */
final class ShrinkImage
{
    /**
     * @param  int  $pixels  sisi maksimum hasil (cover = dipotong persegi, contain = rasio asli dalam kotak)
     * @param  int  $maxKilobytes  batas ukuran file yang diterima server
     */
    public static function apply(FileUpload $field, int $pixels, int $maxKilobytes, string $mode = 'contain'): FileUpload
    {
        return $field
            ->automaticallyResizeImagesMode($mode)
            ->automaticallyResizeImagesToWidth((string) $pixels)
            ->automaticallyResizeImagesToHeight((string) $pixels)
            ->automaticallyUpscaleImagesWhenResizing(false)
            ->rule('max:'.$maxKilobytes);
    }
}
