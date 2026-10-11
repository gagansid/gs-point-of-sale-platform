<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier member (ADR 0013, Q64): batas total belanja 12 bulan bergulir, pengali poin, dan diskon tambahan.
 * Member masuk tier tertinggi yang batasnya terpenuhi; di bawah tier terendah = tanpa tier.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $min_spend_12m
 * @property string $point_multiplier
 * @property string $discount_percent ditambahkan ke diskon member dasar
 */
final class MemberTier extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['name', 'min_spend_12m', 'point_multiplier', 'discount_percent'];

    protected $attributes = ['min_spend_12m' => '0.00', 'point_multiplier' => '1.00', 'discount_percent' => '0.00'];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['name', 'min_spend_12m', 'point_multiplier', 'discount_percent'];
    }

    protected function casts(): array
    {
        return [
            'min_spend_12m' => 'decimal:2',
            'point_multiplier' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    /**
     * Tier tenant aktif, urut batas belanja naik.
     *
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        return self::query()->orderBy('min_spend_12m')->orderBy('name')->get();
    }

    /**
     * Tier tertinggi yang batasnya terpenuhi; null = di bawah tier terendah.
     *
     * @param  Collection<int, self>  $tiers  urut naik (ordered())
     */
    public static function forSpend(string $spend, Collection $tiers): ?self
    {
        return $tiers->filter(fn (self $tier): bool => Money::compare($spend, $tier->min_spend_12m) >= 0)->last();
    }

    /**
     * Tier berikutnya di atas tier ini (untuk progres di halaman member).
     *
     * @param  Collection<int, self>  $tiers  urut naik (ordered())
     */
    public static function nextAfter(?self $tier, string $spend, Collection $tiers): ?self
    {
        return $tiers->first(fn (self $t): bool => Money::compare($t->min_spend_12m, $spend) > 0 && $t->id !== $tier?->id);
    }
}
