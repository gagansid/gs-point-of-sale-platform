<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromotionType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Promo otomatis (ADR 0022). Aturan dihitung App\Services\Order\AutomaticPromotions; ditulis lewat SavePromotion.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property PromotionType $type
 * @property DiscountType|null $discount_type
 * @property string $discount_value
 * @property string|null $max_discount
 * @property string|null $min_subtotal
 * @property int|null $buy_qty
 * @property int|null $get_qty
 * @property string $applies_to all / products / categories
 * @property list<string>|null $product_ids
 * @property list<string>|null $category_ids
 * @property list<string>|null $outlet_ids null = semua outlet
 * @property list<int>|null $days 1 = Senin … 7 = Minggu; null = setiap hari
 * @property string|null $time_start HH:MM waktu outlet
 * @property string|null $time_end
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property bool $is_active
 */
final class Promotion extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    public const APPLIES_ALL = 'all';

    public const APPLIES_PRODUCTS = 'products';

    public const APPLIES_CATEGORIES = 'categories';

    protected $fillable = [
        'name', 'type', 'discount_type', 'discount_value', 'max_discount', 'min_subtotal', 'buy_qty', 'get_qty',
        'applies_to', 'product_ids', 'category_ids', 'outlet_ids', 'days', 'time_start', 'time_end', 'starts_on', 'ends_on', 'is_active',
    ];

    /** Kolom yang dicatat di Riwayat perubahan (Q58). */
    public static function auditAttributes(): array
    {
        return ['name', 'type', 'discount_type', 'discount_value', 'max_discount', 'min_subtotal', 'buy_qty', 'get_qty', 'applies_to', 'days', 'time_start', 'time_end', 'starts_on', 'ends_on', 'is_active'];
    }

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'buy_qty' => 'integer',
            'get_qty' => 'integer',
            'product_ids' => 'array',
            'category_ids' => 'array',
            'outlet_ids' => 'array',
            'days' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /** Berlaku di outlet & waktu lokal $at (hari, jam, tanggal). */
    public function isRunning(string $outletId, CarbonImmutable $at): bool
    {
        $date = $at->toDateString();
        $time = $at->format('H:i');

        return $this->is_active
            && ($this->outlet_ids === null || $this->outlet_ids === [] || in_array($outletId, $this->outlet_ids, true))
            && ($this->starts_on === null || $this->starts_on->toDateString() <= $date)
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $date)
            && ($this->days === null || $this->days === [] || in_array($at->isoWeekday(), array_map('intval', $this->days), true))
            && self::withinHours($this->time_start, $this->time_end, $time);
    }

    /** Jam mulai–selesai; selesai < mulai = lewat tengah malam (mis. 22:00–02:00). */
    private static function withinHours(?string $start, ?string $end, string $time): bool
    {
        if ($start === null || $end === null) {
            return true;
        }

        return $start <= $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
    }

    /** Produk (dengan kategori) termasuk sasaran promo. */
    public function covers(string $productId, ?string $categoryId): bool
    {
        return match ($this->applies_to) {
            self::APPLIES_PRODUCTS => in_array($productId, $this->product_ids ?? [], true),
            self::APPLIES_CATEGORIES => $categoryId !== null && in_array($categoryId, $this->category_ids ?? [], true),
            default => true,
        };
    }
}
