<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Mencatat siapa yang membuat (created_by) & terakhir mengubah (updated_by) data untuk audit
 * (docs/standards/ui/components/audit-info.md). Pelaku = user login (dashboard/API) atau, untuk data
 * platform, admin login (authorModel() = Admin). Tanpa pelaku (job, seeder, CLI) = null.
 * Query massal (->update()) tidak melewati event ini.
 *
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @mixin Model
 */
trait RecordsAuthor
{
    protected static function bootRecordsAuthor(): void
    {
        static::creating(function (Model $model): void {
            $actor = self::currentAuthorId();
            $model->setAttribute('created_by', $model->getAttribute('created_by') ?? $actor);
            $model->setAttribute('updated_by', $model->getAttribute('updated_by') ?? $actor);
        });

        static::updating(function (Model $model): void {
            // Hanya bila ada perubahan nyata; pelaku tidak dikenal tidak menghapus pengubah sebelumnya
            if (($actor = self::currentAuthorId()) !== null) {
                $model->setAttribute('updated_by', $actor);
            }
        });
    }

    /** @return class-string<User|Admin> */
    public static function authorModel(): string
    {
        return User::class;
    }

    /** Nama pembuat ("created_by") atau pengubah ("updated_by"); null bila tidak tercatat. */
    public function authorName(string $column): ?string
    {
        $id = $this->getAttribute($column);

        if (! is_string($id)) {
            return null;
        }

        // Satu query per pelaku per request (tabel & detail menampilkan nama berulang)
        static $names = [];
        $model = static::authorModel();

        return $names[$model][$id] ??= $model::query()->withoutGlobalScopes()->whereKey($id)->value('name');
    }

    private static function currentAuthorId(): ?string
    {
        $model = static::authorModel();
        // Guard aktif (Filament / Sanctum memanggil shouldUse), lalu guard khusus model pelaku
        $actor = Auth::user();

        if (! $actor instanceof $model) {
            $actor = Auth::guard($model === Admin::class ? 'admin' : 'web')->user();
        }

        return $actor instanceof $model ? (string) $actor->getKey() : null;
    }
}
