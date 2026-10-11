<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\VoucherSource;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Voucher (ADR 0013, Q66): kode promo owner atau voucher ulang tahun pribadi (member_id, tanpa kode). Potongan
 * nominal/persen dicatat sebagai diskon order (voucher_discount); aturan pakai di App\Services\Member\Vouchers.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $code huruf besar; null untuk voucher pribadi
 * @property string $name
 * @property VoucherSource $source
 * @property DiscountType $type
 * @property string $value
 * @property string|null $max_discount maks. potongan untuk voucher persen
 * @property string $min_spend minimal subtotal (setelah diskon item)
 * @property int|null $quota total pemakaian; null = tanpa batas
 * @property int|null $per_member_quota
 * @property int $used_count cache pemakaian (status used)
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $member_only
 * @property list<string>|null $tier_ids null = semua tier
 * @property list<string>|null $outlet_ids null = semua outlet
 * @property string|null $member_id pemilik voucher pribadi
 * @property bool $is_active
 */
final class Voucher extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = [
        'code', 'name', 'type', 'value', 'max_discount', 'min_spend', 'quota', 'per_member_quota',
        'starts_at', 'ends_at', 'member_only', 'tier_ids', 'outlet_ids', 'is_active',
    ];

    protected $attributes = ['source' => 'owner', 'min_spend' => '0.00', 'used_count' => 0, 'member_only' => false, 'is_active' => true];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['code', 'name', 'type', 'value', 'max_discount', 'min_spend', 'quota', 'per_member_quota', 'starts_at', 'ends_at', 'member_only', 'tier_ids', 'outlet_ids', 'is_active'];
    }

    protected function casts(): array
    {
        return [
            'source' => VoucherSource::class,
            'type' => DiscountType::class,
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'quota' => 'integer',
            'per_member_quota' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'member_only' => 'boolean',
            'tier_ids' => 'array',
            'outlet_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<VoucherRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /** Kode/nama untuk struk & laporan. */
    public function label(): string
    {
        return $this->code ?? $this->name;
    }

    /** Ringkasan potongan, mis. "Rp10.000" atau "15% (maks. Rp20.000)". */
    public function benefitLabel(): string
    {
        if ($this->type === DiscountType::Fixed) {
            return Money::format($this->value);
        }

        $percent = rtrim(rtrim($this->value, '0'), '.').'%';

        return $this->max_discount !== null ? $percent.' (maks. '.Money::format($this->max_discount).')' : $percent;
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
