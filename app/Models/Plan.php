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
 * @property array<string, int>|null $limits batas per kuota (key master data quotas); tidak ada = tanpa batas
 * @property list<string> $features key dari master data features (ADR 0025)
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 */
final class Plan extends Model
{
    use HasUuids, RecordsAuthor;

    protected $fillable = ['code', 'name', 'description', 'price_monthly', 'price_yearly', 'limits', 'features', 'is_active', 'is_public', 'sort_order'];

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
            'limits' => 'array',
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

    public function limit(string $quota): ?int
    {
        $limit = $this->limits[$quota] ?? null;

        return $limit !== null ? (int) $limit : null;
    }

    /** Ringkasan batas, mis. "Outlet aktif 3 · Karyawan aktif 10"; kosong = tanpa batas. */
    public function limitsSummary(): string
    {
        return Quota::summary($this->limits ?? []);
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
