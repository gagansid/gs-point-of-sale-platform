<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Outlet ikut program member atau tidak (ADR 0013, Q60). Tanpa baris = ikut (pola ADR 0011).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property bool $is_enabled
 */
final class OutletMemberProgram extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'outlet_member_program';

    protected $fillable = ['outlet_id', 'is_enabled'];

    protected $attributes = ['is_enabled' => true];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /** Program member berlaku di outlet ini: aktif di bisnis dan outlet tidak dikecualikan. */
    public static function activeAt(string $outletId): bool
    {
        return MemberSetting::current()->enabled
            && self::query()->where('outlet_id', $outletId)->where('is_enabled', false)->doesntExist();
    }
}
