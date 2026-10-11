<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Support\LogReader;

/** Kosongkan satu file log (Sistem → Log) mis. setelah error ditangani dan file terlalu besar. */
final class ClearLogFile
{
    /** @throws BusinessException */
    public function handle(string $name): void
    {
        $path = LogReader::path($name) ?? throw BusinessException::of(ErrorCode::NotFound, 'File log tidak ditemukan');

        file_put_contents($path, '');
    }
}
