<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Karyawan tenant (owner, manager, supervisor, kasir).
 *
 * Login mencari user tanpa tenant scope lewat TenantUserProvider & PersonalAccessToken,
 * karena tenant baru diketahui setelah user ditemukan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $outlet_id
 * @property string $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $pin
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property UserRole $role
 * @property CarbonImmutable|null $email_verified_at
 * @property bool $is_active
 * @property CarbonImmutable|null $last_login_at
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** tenant_id sengaja tidak fillable: selalu dari tenant context (BelongsToTenant). */
    protected $fillable = [
        'outlet_id',
        'name',
        'email',
        'password',
        'pin',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
        ];
    }

    /** Hanya panel /dashboard, hanya owner & manager aktif. Panel /admin memakai tabel admins. */
    /**
     * Role memiliki permission, tanpa memperhitungkan kunci hanya-baca (ADR 0009). Hanya untuk
     * keputusan MELIHAT (viewAny/view di Policy); untuk melakukan aksi selalu pakai $user->can().
     */
    public function hasPermission(string $permission): bool
    {
        $role = $this->getAttribute('role');

        return $role instanceof UserRole && $role->allows($permission);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'dashboard' && $this->is_active && $this->role->canAccessDashboard();
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
