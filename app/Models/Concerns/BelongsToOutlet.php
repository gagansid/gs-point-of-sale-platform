<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Outlet;
use App\Models\Scopes\OutletScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model milik satu outlet (ADR 0010). Dipakai bersama BelongsToTenant.
 */
trait BelongsToOutlet
{
    protected static function bootBelongsToOutlet(): void
    {
        static::addGlobalScope(new OutletScope);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
