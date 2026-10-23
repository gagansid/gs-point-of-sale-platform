<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use App\Notifications\ResetUserPassword;
use App\Support\RolePermissions;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
 * @property string|null $phone nomor HP +62… (profil)
 * @property string|null $avatar_path foto profil di disk public
 * @property string|null $password
 * @property string|null $pin
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property UserRole $role
 * @property list<string>|null $permissions_granted izin tambahan di atas role (ADR 0026, opsi B)
 * @property list<string>|null $permissions_revoked izin role yang dicabut untuk karyawan ini
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $username
 * @property bool $is_active
 * @property CarbonImmutable|null $last_login_at
 * @property bool $daily_summary_email ringkasan harian lewat email (Q57)
 * @property CarbonImmutable|null $last_daily_summary_on tanggal lokal ringkasan terakhir yang dikirim
 */
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids, LogsChanges, Notifiable, RecordsAuthor, SoftDeletes;

    /** @var array{key: string, permissions: list<string>}|null memo grantedPermissions() per instance */
    private ?array $grantedPermissionsMemo = null;

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return [
            'name',
            'email',
            'username',
            'role',
            'is_active',
            'web_cashier_access',
            'phone',
            // Pengecualian hak akses per karyawan (ADR 0026)
            'permissions_granted',
            'permissions_revoked',
        ];
    }

    /** Foto profil di topbar & menu akun; tanpa foto = inisial nama (bawaan Filament). */
    public function getFilamentAvatarUrl(): ?string
    {
        // Aman untuk model yang dimuat sebagian kolom (strict mode)
        $path = $this->getAttributes()['avatar_path'] ?? null;

        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /** tenant_id sengaja tidak fillable: selalu dari tenant context (BelongsToTenant). */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'pin',
        'role',
        'is_active',
        'web_cashier_access',
        'daily_summary_email',
        'phone',
    ];

    /** @var array<string, mixed> Bawaan kolom (Q54) agar tersedia sebelum model dimuat ulang */
    protected $attributes = ['web_cashier_access' => false, 'phone' => null, 'avatar_path' => null];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected static function booted(): void
    {
        // Bawaan ringkasan harian (Q57): owner aktif, role lain tidak — kecuali diisi eksplisit
        static::creating(function (User $user): void {
            if (! array_key_exists('daily_summary_email', $user->getAttributes())) {
                $user->daily_summary_email = $user->role === UserRole::Owner;
            }
        });

        // Audit log (Q58): kata sandi/PIN diganti dicatat TANPA nilainya
        static::updated(function (User $user): void {
            $secrets = array_values(array_filter(['password', 'pin'], fn (string $column): bool => $user->wasChanged($column)));

            if ($secrets !== []) {
                activity('User')->performedOn($user)->event('updated')
                    ->withProperties(['secrets' => $secrets])
                    ->log('credentials');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'role' => UserRole::class,
            'permissions_granted' => 'array',
            'permissions_revoked' => 'array',
            'is_active' => 'boolean',
            'web_cashier_access' => 'boolean',
            'daily_summary_email' => 'boolean',
            'last_daily_summary_on' => 'immutable_date',
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

        // Hak akses per bisnis & pengecualian karyawan (ADR 0026)
        return $role instanceof UserRole && UserRole::isPermission($permission)
            && in_array($permission, $this->grantedPermissions(), true);
    }

    /**
     * Permission yang berlaku untuk user ini (untuk /auth/me; app menyembunyikan menu).
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        $role = $this->getAttribute('role');

        if (! $role instanceof UserRole) {
            return [];
        }

        // Memo per instance: can() dipanggil ratusan kali per render. Kunci berubah bila role, tenant, pengecualian,
        // atau pengaturan hak akses bisnis (RolePermissions::flush) berubah
        $attributes = $this->getAttributes();
        $key = implode('|', [
            $role->value, (string) $this->getAttribute('tenant_id'), RolePermissions::version(),
            (string) json_encode($attributes['permissions_granted'] ?? null), (string) json_encode($attributes['permissions_revoked'] ?? null),
        ]);

        if ($this->grantedPermissionsMemo !== null && $this->grantedPermissionsMemo['key'] === $key) {
            return $this->grantedPermissionsMemo['permissions'];
        }

        $granted = RolePermissions::granted($role, $this->getAttribute('tenant_id'));
        $permissions = $role->isConfigurable()
            ? RolePermissions::applyOverrides($granted, $this->permissionOverride('permissions_granted'), $this->permissionOverride('permissions_revoked'))
            : $granted;

        $this->grantedPermissionsMemo = ['key' => $key, 'permissions' => $permissions];

        return $permissions;
    }

    /** Karyawan ini punya pengecualian hak akses (badge "Khusus" di Karyawan). */
    public function hasPermissionOverrides(): bool
    {
        return $this->permissionOverride('permissions_granted') !== [] || $this->permissionOverride('permissions_revoked') !== [];
    }

    /**
     * Daftar pengecualian dari atribut mentah: aman untuk model yang dimuat sebagian kolom (strict mode).
     *
     * @return list<string>
     */
    public function permissionOverride(string $column): array
    {
        $raw = $this->getAttributes()[$column] ?? null;
        $list = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }

    /**
     * Panel /dashboard penuh (Beranda & menu sesuai permission). Tanpa ini, pemegang report.view_shift hanya melihat
     * menu Laporan (bawaan supervisor, Q50); owner bisa memberikannya ke role lain (ADR 0026).
     */
    public function hasFullDashboard(): bool
    {
        return $this->hasPermission('dashboard.access');
    }

    /**
     * Boleh mengelola karyawan ini (ADR 0026): owner semua karyawan; pengelola karyawan lain hanya role di bawahnya
     * dan bukan dirinya sendiri (cegah naik hak setelah user.manage didelegasikan).
     */
    public function canManageEmployee(self $employee): bool
    {
        return $employee->tenant_id === $this->tenant_id
            && ($this->hasPermission('access.manage') || ($employee->isNot($this) && $employee->role->rank() < $this->role->rank()));
    }

    /** Boleh memberikan role ini ke karyawan (ADR 0026): owner semua; lainnya hanya role di bawahnya. */
    public function canAssignRole(UserRole $role): bool
    {
        return $this->hasPermission('access.manage') || $role->rank() < $this->role->rank();
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
        return $this->canAccessPanelIgnoringVerification($panel) && ! $this->needsVerifiedEmailForDashboard();
    }

    /** Syarat akses panel selain verifikasi email (untuk membedakan pesan login). */
    public function canAccessPanelIgnoringVerification(Panel $panel): bool
    {
        return $panel->getId() === 'dashboard' && $this->is_active
            && ($this->hasPermission('dashboard.access') || $this->hasPermission('report.view_shift'));
    }

    /**
     * Role yang login dengan PIN/username (supervisor, kasir) wajib email terverifikasi untuk dashboard (Q46), juga
     * bila owner memberi mereka akses dashboard (ADR 0026). Owner/manager tidak diblokir: owner baru daftar mandiri
     * harus tetap bisa masuk (Q31).
     */
    public function needsVerifiedEmailForDashboard(): bool
    {
        return ! $this->role->canUsePasswordLogin() && ! $this->hasVerifiedEmail();
    }

    /**
     * Boleh memakai kasir web (Q54): akun aktif yang bisa bertransaksi, dan diberi akses di menu
     * Karyawan. Owner (access.manage) selalu boleh agar tidak bisa terkunci sendiri; Kelola karyawan yang didelegasikan
     * (ADR 0026) tetap mengikuti toggle.
     */
    public function canUseWebCashier(): bool
    {
        return $this->is_active
            && $this->hasPermission('order.create')
            && ($this->hasPermission('access.manage') || $this->web_cashier_access);
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
