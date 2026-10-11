<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit log (Q58, R7): catat dibuat/diubah/dihapus untuk kolom di auditAttributes() saja — nilai lama & baru,
 * pelaku = user login (guard aktif). Kolom rahasia (password, PIN) tidak pernah masuk daftar.
 *
 * @mixin Model
 */
trait LogsChanges
{
    use LogsActivity;

    /** @return list<string> kolom yang dicatat */
    abstract public static function auditAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(class_basename(static::class))
            ->logOnly(static::auditAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
