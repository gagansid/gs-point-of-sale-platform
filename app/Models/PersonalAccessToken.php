<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token Sanctum untuk user & device.
 *
 * Pemilik token (tokenable) dimuat tanpa TenantScope karena tenant baru diketahui dari
 * pemilik token itu sendiri; token yang valid sudah membuktikan identitasnya.
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}
