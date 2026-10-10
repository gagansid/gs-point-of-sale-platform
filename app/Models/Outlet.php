<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string $timezone
 * @property string $tax_rate
 * @property string $service_charge_rate
 * @property bool $tax_inclusive
 * @property int $rounding
 * @property string|null $receipt_header
 * @property string|null $receipt_footer
 * @property bool $receipt_show_rounding baris pembulatan tampil di struk (Q48)
 * @property array<string, int|float>|null $discount_limits
 * @property bool $is_active
 */
final class Outlet extends Model
{
    /** @use HasFactory<OutletFactory> */
    use BelongsToTenant, HasFactory, HasUuids, RecordsAuthor, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'address',
        'timezone',
        'tax_rate',
        'service_charge_rate',
        'tax_inclusive',
        'rounding',
        'receipt_header',
        'receipt_footer',
        'receipt_show_rounding',
        'discount_limits',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'receipt_show_rounding' => 'boolean',
            'rounding' => 'integer',
            'discount_limits' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** Waktu sekarang di zona outlet (ADR 0001). */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    /**
     * Tanggal lokal outlet (YYYY-MM-DD) untuk nomor order, sequence harian, dan "hari ini".
     * Jangan memakai today() untuk logika bisnis: server berjalan di UTC.
     */
    public function localDate(?CarbonInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone)->toDateString();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return HasMany<ProductStock, $this> */
    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
