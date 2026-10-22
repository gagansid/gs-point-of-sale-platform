<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Hak akses satu role di satu bisnis (ADR 0026). Ditulis lewat App\Actions\User\UpdateRolePermissions; dibaca lewat
 * App\Services\RolePermissions. Tanpa baris = bawaan UserRole::permissions().
 *
 * @property string $id
 * @property string $tenant_id
 * @property UserRole $role
 * @property list<string> $permissions
 */
final class RolePermission extends Model
{
    use BelongsToTenant, HasUuids, RecordsAuthor;

    protected $fillable = ['role', 'permissions'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }
}
