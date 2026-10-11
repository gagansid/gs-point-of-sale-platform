<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Announcement;
use Carbon\CarbonInterface;

/**
 * Pengumuman super admin ke semua tenant: banner dashboard, kasir web, dan GET /v1/system/status.
 * Teks polos (di-escape saat tampil). ends_at null = tayang sampai diakhiri.
 */
final class SaveAnnouncement
{
    /** @throws BusinessException */
    public function handle(?Announcement $announcement, string $title, string $body, CarbonInterface $startsAt, ?CarbonInterface $endsAt): Announcement
    {
        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Waktu selesai harus setelah waktu mulai', ['ends_at' => ['Harus setelah waktu mulai']]);
        }

        $announcement ??= new Announcement;
        $announcement->fill([
            'title' => mb_substr(trim($title), 0, 150),
            'body' => trim($body),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->save();

        return $announcement;
    }

    /** Akhiri sekarang (tetap tersimpan sebagai riwayat). */
    public function end(Announcement $announcement): Announcement
    {
        $announcement->forceFill(['ends_at' => now()])->save();

        return $announcement;
    }
}
