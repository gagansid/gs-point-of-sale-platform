<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paket langganan SaaS (ADR 0024), data platform (tanpa tenant_id) dikelola di panel /admin.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $price_monthly
 * @property string $price_yearly
 * @property int|null $max_outlets
 * @property list<string> $features key dari master data features (ADR 0025)
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 */
final class Plan extends Model
{
    use HasUuids, RecordsAuthor;

    protected $fillable = ['code', 'name', 'description', 'price_monthly', 'price_yearly', 'max_outlets', 'features', 'is_active', 'is_public', 'sort_order'];

    protected $attributes = ['features' => '[]', 'is_active' => true, 'is_public' => true, 'sort_order' => 0];

    public static function authorModel(): string
    {
        return Admin::class;
    }

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'max_outlets' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function price(BillingCycle $cycle): string
    {
        return $cycle === BillingCycle::Monthly ? $this->price_monthly : $this->price_yearly;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
