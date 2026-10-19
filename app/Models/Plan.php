<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property CarbonImmutable|null $available_from paket bisa dipilih owner mulai (null = sejak dibuat)
 * @property CarbonImmutable|null $available_until paket bisa dipilih owner sampai (null = tanpa batas waktu)
 * @property int $sort_order
 */
final class Plan extends Model
{
    use HasUuids, RecordsAuthor;

    protected $fillable = ['code', 'name', 'description', 'price_monthly', 'price_yearly', 'limits', 'features', 'is_active', 'is_public', 'available_from', 'available_until', 'sort_order'];

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
            'available_from' => 'immutable_datetime',
            'available_until' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    public const PERIOD_UNLIMITED = 'unlimited';

    public const PERIOD_SCHEDULED = 'scheduled';

    public const PERIOD_RUNNING = 'running';

    public const PERIOD_ENDED = 'ended';

    /** Status periode untuk filter & badge admin. */
    public const PERIODS = [
        self::PERIOD_UNLIMITED => 'Tanpa batas waktu',
        self::PERIOD_SCHEDULED => 'Belum mulai',
        self::PERIOD_RUNNING => 'Sedang berjalan',
        self::PERIOD_ENDED => 'Sudah berakhir',
    ];

    /** Aktif & di dalam periode: bisa dipilih owner untuk tagihan baru (paket tenant sendiri tetap bisa diperpanjang). */
    public function isAvailable(?CarbonImmutable $at = null): bool
    {
        return $this->is_active && in_array($this->periodStatus($at), [self::PERIOD_UNLIMITED, self::PERIOD_RUNNING], true);
    }

    public function periodStatus(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();

        return match (true) {
            $this->available_from === null && $this->available_until === null => self::PERIOD_UNLIMITED,
            $this->available_from !== null && $this->available_from->isAfter($at) => self::PERIOD_SCHEDULED,
            $this->available_until !== null && ! $this->available_until->isAfter($at) => self::PERIOD_ENDED,
            default => self::PERIOD_RUNNING,
        };
    }

    /**
     * Paket di dalam periode (tanpa memeriksa is_active).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeWithinPeriod(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now();

        return $query->where(fn (Builder $q) => $q->whereNull('available_from')->orWhere('available_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('available_until')->orWhere('available_until', '>', $at));
    }

    /**
     * Filter admin per status periode.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePeriodStatus(Builder $query, string $status, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now();

        return match ($status) {
            self::PERIOD_UNLIMITED => $query->whereNull('available_from')->whereNull('available_until'),
            self::PERIOD_SCHEDULED => $query->where('available_from', '>', $at),
            self::PERIOD_ENDED => $query->where('available_until', '<=', $at),
            self::PERIOD_RUNNING => $query->where(fn (Builder $q) => $q->whereNotNull('available_from')->orWhereNotNull('available_until'))
                ->withinPeriod($at),
            default => $query,
        };
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
