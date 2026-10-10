<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ketersediaan satu opsi di satu outlet (tabel outlet_option, ADR 0011 / Q43). Tanpa baris = tersedia.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $option_id
 * @property bool $is_available
 */
final class OutletOption extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'outlet_option';

    protected $fillable = ['outlet_id', 'option_id', 'is_available'];

    protected $attributes = ['is_available' => true];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    /**
     * ID opsi yang ditandai habis di outlet.
     *
     * @return list<string>
     */
    public static function unavailableIds(string $outletId): array
    {
        return self::query()->where('outlet_id', $outletId)->where('is_available', false)
            ->pluck('option_id')->values()->all();
    }

    /** @return BelongsTo<Option, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
