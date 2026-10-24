<?php

declare(strict_types=1);

use App\Filament\Shared\Forms\ShrinkImage;
use Filament\Forms\Components\FileUpload;

/** Foto kamera diperkecil di browser; batas ukuran hanya di server agar FilePond tidak menolak file asli. */
it('mengatur resize di browser tanpa maxSize klien, batas ukuran lewat aturan server', function () {
    $field = ShrinkImage::apply(FileUpload::make('avatar'), 512, 2048, 'cover');

    expect($field->getAutomaticallyResizeImagesMode())->toBe('cover')
        ->and($field->getAutomaticallyResizeImagesWidth())->toBe('512')
        ->and($field->getAutomaticallyResizeImagesHeight())->toBe('512')
        ->and($field->getMaxSize())->toBeNull();
});
