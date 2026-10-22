<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Hak akses satu role di satu bisnis (ADR 0026). Ditulis lewat App\Actions\User\UpdateRolePermissions; dibaca lewat
 * App\Support\RolePermissions. Tanpa baris = bawaan UserRole::permissions().
 *
 * @property string $id
 * @property string $tenant_id
 * @property UserRole $role
 * @property list<string> $permissions
 */
final class RolePermission extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['role', 'permissions'];

    /** Dicatat di Riwayat perubahan (Q58): role & daftar permission. */
    public static function auditAttributes(): array
    {
        return ['role', 'permissions'];
    }

    /**
     * logOnlyDirty tidak mencatat `role` bila hanya izin yang berubah: simpan role di setiap log agar Riwayat perubahan
     * selalu tahu role mana yang diubah (juga setelah baris dihapus = kembali ke bawaan).
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        // Model konkret (activitylog.activity_model = App\Models\ActivityLog) menyimpan properties sebagai Collection
        if ($activity instanceof Model) {
            /** @var Collection<string, mixed> $properties */
            $properties = $activity->getAttribute('properties') ?? new Collection;
            $activity->setAttribute('properties', $properties->put('role', $this->role->value));
        }
    }

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }
}
