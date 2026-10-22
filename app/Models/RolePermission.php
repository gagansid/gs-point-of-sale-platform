<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }
}
