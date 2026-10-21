<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit log (Q58, R7): satu baris per perubahan data penting, dibuat spatie/laravel-activitylog lewat
 * App\Models\Concerns\LogsChanges. Dibaca per tenant (TenantScope, fail-closed). tenant_id diambil dari
 * data yang diubah, lalu tenant context; tidak memakai BelongsToTenant agar pencatatan tidak pernah
 * menggagalkan perubahan (mis. dari panel /admin tanpa tenant context).
 *
 * @property string|null $tenant_id
 */
final class ActivityLog extends Activity
{
    protected static function booted(): void
    {
        self::addGlobalScope(new TenantScope);

        self::creating(function (ActivityLog $log): void {
            // Data platform (mis. Bank) tidak punya kolom tenant_id: baca atribut mentah agar mode strict tidak melempar
            $subjectTenant = $log->subject?->getAttributes()['tenant_id'] ?? null;
            $log->tenant_id ??= is_string($subjectTenant) ? $subjectTenant : TenantContext::id();
        });
    }
}
