<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Database\Factories\MemberFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Member (pelanggan terdaftar) milik bisnis, berlaku di semua outlet yang ikut program (ADR 0013, Q60–Q61).
 * Hapus = anonimkan (anonymized_at): data pribadi dikosongkan, baris tetap untuk transaksi & ledger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $phone format +62…
 * @property string|null $email
 * @property int|null $birth_day
 * @property int|null $birth_month
 * @property string|null $notes
 * @property int $points_balance
 * @property string|null $tier_id tier saat ini (M5); null = di bawah tier terendah
 * @property string $spend_12m total belanja 12 bulan bergulir (cache, dihitung TierEvaluator)
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property string $total_spent
 * @property int $visit_count
 * @property CarbonImmutable|null $last_visit_at
 * @property string|null $joined_outlet_id
 * @property CarbonImmutable|null $consent_at
 * @property bool $is_active
 * @property CarbonImmutable|null $anonymized_at
 */
final class Member extends Model implements AuthenticatableContract
{
    /** @use HasFactory<MemberFactory> */
    use Authenticatable, BelongsToTenant, HasFactory, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['name', 'phone', 'email', 'birth_day', 'birth_month', 'notes', 'joined_outlet_id', 'is_active'];

    protected $hidden = ['password', 'pin'];

    protected $attributes = ['is_active' => true, 'points_balance' => 0, 'visit_count' => 0];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return [
            'name',
            'phone',
            'email',
            'birth_day',
            'birth_month',
            'is_active',
        ];
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'must_change_password' => 'boolean',
            'birth_day' => 'integer',
            'birth_month' => 'integer',
            'points_balance' => 'integer',
            'spend_12m' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'visit_count' => 'integer',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'immutable_datetime',
            'last_visit_at' => 'immutable_datetime',
            'consent_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function joinedOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'joined_outlet_id');
    }

    /** @return BelongsTo<MemberTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(MemberTier::class, 'tier_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Tanpa kolom remember_token: halaman member memakai sesi biasa (tanpa "ingat saya"). */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /** Bisa dipasang ke transaksi baru. */
    public function isUsable(): bool
    {
        return $this->is_active && ! $this->isAnonymized();
    }

    public function maskedPhone(): string
    {
        return Phone::masked($this->phone);
    }

    /**
     * Cari lewat nomor HP (format apa pun), kode, atau nama.
     *
     * @param  Builder<Member>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        self::applySearch($query, $term);
    }

    /**
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $phone = Phone::normalize($term);

        $query->where(function (Builder $q) use ($term, $phone): void {
            $q->where('code', mb_strtoupper($term))
                ->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);

            if ($phone !== null) {
                $q->orWhere('phone', $phone);
            } elseif (preg_match('/^\d{4,}$/', $term) === 1) {
                // Sebagian nomor (mis. 4 digit terakhir)
                $q->orWhere('phone', 'like', '%'.$term);
            }
        });

        return $query;
    }
}
