<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Add-on langganan (ADR 0025 C1), data platform dikelola di panel /admin. Jenis `feature`: memberi fitur
 * `feature_key` (master data features). Jenis `quota`: menambah kuota `quota_key` (master data quotas) sebanyak
 * `quota_amount` per unit; boleh beberapa unit.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property string|null $feature_key
 * @property string|null $quota_key
 * @property int|null $quota_amount
 * @property string $price_monthly
 * @property string $price_yearly
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 */
final class Addon extends Model
{
    use HasUuids, RecordsAuthor;

    public const TYPE_FEATURE = 'feature';

    public const TYPE_QUOTA = 'quota';

    public const TYPES = [self::TYPE_FEATURE => 'Fitur', self::TYPE_QUOTA => 'Tambahan kuota'];

    protected $fillable = ['code', 'name', 'description', 'type', 'feature_key', 'quota_key', 'quota_amount', 'price_monthly', 'price_yearly', 'is_active', 'is_public', 'sort_order'];

    protected $attributes = ['is_active' => true, 'is_public' => true, 'sort_order' => 0];

    public static function authorModel(): string
    {
        return Admin::class;
    }

    protected function casts(): array
    {
        return [
            'quota_amount' => 'integer',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isQuota(): bool
    {
        return $this->type === self::TYPE_QUOTA;
    }

    public function price(BillingCycle $cycle): string
    {
        return $cycle === BillingCycle::Monthly ? $this->price_monthly : $this->price_yearly;
    }

    /** "Fitur: Self-order QR meja" / "+1 Outlet aktif per unit" */
    public function benefit(): string
    {
        return $this->isQuota()
            ? '+'.number_format((int) $this->quota_amount, 0, ',', '.').' '.(Quota::options()[(string) $this->quota_key] ?? $this->quota_key).' per unit'
            : 'Fitur: '.(Feature::options()[(string) $this->feature_key] ?? $this->feature_key);
    }

    /** @return HasMany<TenantAddon, $this> */
    public function tenantAddons(): HasMany
    {
        return $this->hasMany(TenantAddon::class)->withoutGlobalScopes();
    }
}
