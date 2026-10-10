<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use App\Notifications\ResetUserPassword;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * Karyawan tenant (owner, manager, supervisor, kasir).
 *
 * Login mencari user tanpa tenant scope lewat TenantUserProvider & PersonalAccessToken,
 * karena tenant baru diketahui setelah user ditemukan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $pin
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property UserRole $role
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $username
 * @property bool $is_active
 * @property CarbonImmutable|null $last_login_at
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids, Notifiable, RecordsAuthor, SoftDeletes;

    /** tenant_id sengaja tidak fillable: selalu dari tenant context (BelongsToTenant). */
    protected $fillable = [
        'name',
        'email',
        'username',
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

    /** Link lupa kata sandi berbahasa Indonesia (bawaan Laravel: bahasa Inggris). */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetUserPassword($token));
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

    /**
     * Outlet yang ditugaskan (outlet_user). Owner (outlet.access_all) tidak perlu ditugaskan:
     * pakai accessibleOutlets() untuk keputusan akses.
     *
     * @return BelongsToMany<Outlet, $this>
     */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class)->withTimestamps();
    }

    /**
     * Outlet yang boleh diakses user (ADR 0010): owner semua outlet tenant, role lain hanya yang
     * ditugaskan. Fail-closed: tanpa penugasan = tidak ada outlet.
     *
     * @return Builder<Outlet>
     */
    public function accessibleOutlets(): Builder
    {
        // forTenant: juga dipakai sebelum tenant context terisi (respons login)
        $query = Outlet::forTenant($this->tenant_id)->orderBy('created_at');

        return $this->hasPermission('outlet.access_all')
            ? $query
            : $query->whereIn('id', DB::table('outlet_user')->where('user_id', $this->id)->select('outlet_id'));
    }

    /** @var list<string>|null */
    private ?array $outletIdsCache = null;

    /** @return list<string> ID outlet yang boleh diakses (di-cache per instance/request). */
    public function outletIds(): array
    {
        return $this->outletIdsCache ??= $this->accessibleOutlets()->pluck('id')->values()->all();
    }

    public function canAccessOutlet(?string $outletId): bool
    {
        return $outletId !== null && in_array($outletId, $this->outletIds(), true);
    }

    public function forgetOutletIds(): void
    {
        $this->outletIdsCache = null;
    }
}
