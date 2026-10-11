<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Setelan program member per tenant (ADR 0013). Tanpa baris = program belum aktif.
 * Aturan poin (M2) di sini; tier, stempel, voucher di tabel masing-masing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $enabled
 * @property string $earn_amount
 * @property int $earn_points
 * @property string $redeem_value
 * @property int $min_redeem_points
 * @property int $max_redeem_percent
 * @property int $expiry_months 0 = tidak kedaluwarsa
 * @property string $member_discount_percent diskon member % (Q63)
 * @property array{enabled?: bool, type?: string, value?: string, max_discount?: string|null, min_spend?: string}|null $birthday_voucher voucher ulang tahun (M7)
 */
final class MemberSetting extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['enabled', 'earn_amount', 'earn_points', 'redeem_value', 'min_redeem_points', 'max_redeem_percent', 'expiry_months', 'member_discount_percent'];

    /** Bawaan sama dengan kolom DB (model baru sebelum disimpan) */
    protected $attributes = [
        'enabled' => false, 'earn_amount' => '10000.00', 'earn_points' => 1, 'redeem_value' => '100.00',
        'min_redeem_points' => 50, 'max_redeem_percent' => 50, 'expiry_months' => 12, 'member_discount_percent' => '0.00',
    ];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['enabled', 'earn_amount', 'earn_points', 'redeem_value', 'min_redeem_points', 'max_redeem_percent', 'expiry_months', 'member_discount_percent'];
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'earn_amount' => 'decimal:2',
            'earn_points' => 'integer',
            'redeem_value' => 'decimal:2',
            'min_redeem_points' => 'integer',
            'max_redeem_percent' => 'integer',
            'expiry_months' => 'integer',
            'member_discount_percent' => 'decimal:2',
            'birthday_voucher' => 'array',
        ];
    }

    /**
     * Aturan voucher ulang tahun (M7, Q66) dengan nilai bawaan (nonaktif).
     *
     * @return array{enabled: bool, type: string, value: string, max_discount: string|null, min_spend: string}
     */
    public function birthdayVoucher(): array
    {
        $rule = $this->birthday_voucher ?? [];

        return [
            'enabled' => (bool) ($rule['enabled'] ?? false),
            'type' => (string) ($rule['type'] ?? 'fixed'),
            'value' => (string) ($rule['value'] ?? '0'),
            'max_discount' => filled($rule['max_discount'] ?? null) ? (string) $rule['max_discount'] : null,
            'min_spend' => (string) ($rule['min_spend'] ?? '0'),
        ];
    }

    /** Setelan tenant aktif; belum ada = model baru (tidak disimpan) dengan program nonaktif. */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }
}
