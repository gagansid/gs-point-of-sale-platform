<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $option_group_id
 * @property string $name
 * @property string $price_delta
 * @property int $sort_order
 */
final class Option extends Model
{
    /** @use HasFactory<OptionFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['option_group_id', 'name', 'price_delta', 'sort_order'];

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<OptionGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    /**
     * Status habis per outlet (ADR 0011 / Q43); tanpa baris = tersedia.
     *
     * @return HasMany<OutletOption, $this>
     */
    public function outletStates(): HasMany
    {
        return $this->hasMany(OutletOption::class);
    }
}
