<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Banner dari super admin ke semua tenant.
 *
 * @property string $id
 * @property string $title
 * @property string $body
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 */
final class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'title',
        'body',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    private const CACHE_KEY = 'announcements:current';

    protected static function booted(): void
    {
        // Dibaca setiap halaman dashboard, kasir web, & API: cache dihapus saat admin mengubah
        self::saved(fn () => Cache::forget(self::CACHE_KEY));
        self::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Pengumuman tayang (maks. 3 terbaru), cache 60 detik: jadwal mulai/selesai tetap tepat ±1 menit.
     *
     * @return Collection<int, self>
     */
    public static function current(): Collection
    {
        return Cache::remember(self::CACHE_KEY, 60, fn (): Collection => self::query()->active()
            ->orderByDesc('starts_at')->limit(3)->get());
    }

    public function isLive(): bool
    {
        return $this->starts_at->isPast() && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * Pengumuman yang sedang tayang.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
