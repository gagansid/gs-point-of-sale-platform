<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Jobs\RunBackup;
use Illuminate\Support\Facades\Storage;

/**
 * Sistem → Backup (spatie/laravel-backup, disk "backups"). File dipilih dari daftar disk, bukan path
 * dari pengguna.
 */
final class ManageBackups
{
    public const DISK = 'backups';

    /** @return list<array{path: string, name: string, size: int, created: int}> terbaru dulu */
    public function list(): array
    {
        $disk = Storage::disk(self::DISK);
        $folder = (string) config('backup.backup.name');

        return collect($disk->files($folder))
            ->filter(fn (string $path): bool => str_ends_with($path, '.zip'))
            ->map(fn (string $path): array => [
                'path' => $path,
                'name' => basename($path),
                'size' => (int) $disk->size($path),
                'created' => (int) $disk->lastModified($path),
            ])
            ->sortByDesc('created')->values()->all();
    }

    /** Masukkan backup penuh ke antrean (diproses cron dalam ±1 menit). */
    public function queue(): void
    {
        RunBackup::dispatch();
    }

    /** @throws BusinessException */
    public function path(string $name): string
    {
        $match = collect($this->list())->firstWhere('name', $name)
            ?? throw BusinessException::of(ErrorCode::NotFound, 'File backup tidak ditemukan');

        return $match['path'];
    }

    /** @throws BusinessException */
    public function delete(string $name): void
    {
        Storage::disk(self::DISK)->delete($this->path($name));
    }
}
