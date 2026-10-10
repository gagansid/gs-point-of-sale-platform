<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentCategory;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property PaymentCategory $category
 * @property bool $requires_reference
 * @property bool $is_active
 * @property int $sort_order
 */
final class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'category', 'requires_reference', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'category' => PaymentCategory::class,
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Aktif di bisnis dan tidak dinonaktifkan di outlet (ADR 0011 / Q44); tanpa baris outlet = aktif.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveAt(Builder $query, string $outletId): void
    {
        $query->active()->whereDoesntHave('outletSettings', fn (Builder $setting) => $setting
            ->where('outlet_id', $outletId)->where('is_active', false));
    }

    /** @return HasMany<OutletPaymentMethod, $this> */
    public function outletSettings(): HasMany
    {
        return $this->hasMany(OutletPaymentMethod::class);
    }
}
