<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token Sanctum untuk user & device.
 *
 * Pemilik token (tokenable) dimuat tanpa TenantScope karena tenant baru diketahui dari
 * pemilik token itu sendiri; token yang valid sudah membuktikan identitasnya.
 *
 * @property string|null $device_id
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Device asal token user (null untuk token yang bukan dari device kasir).
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withoutGlobalScope(TenantScope::class);
    }

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}
