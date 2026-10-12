<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Meja dengan QR self-order (ADR 0014, Q69). QR berisi URL publik dengan `qr_token` acak; mengganti token membuat QR
 * lama langsung tidak berlaku.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property string $name
 * @property string|null $area
 * @property string $qr_token
 * @property string $short_code kode URL pendek gspos.id/t/{kode}
 * @property bool $is_active
 * @property int $sort_order
 */
final class DiningTable extends Model
{
    use BelongsToOutlet, BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['outlet_id', 'name', 'area', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected $hidden = ['qr_token', 'short_code'];

    /** Huruf/angka tanpa yang mirip (0/O, 1/I/L) agar mudah diketik dari kartu */
    private const SHORT_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Kolom yang dicatat di audit log (Q58); token tidak dicatat. */
    public static function auditAttributes(): array
    {
        return ['name', 'area', 'is_active'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $table): void {
            $table->qr_token ??= self::newToken();
            $table->short_code ??= self::newShortCode();
        });
    }

    public static function newToken(): string
    {
        return Str::random(32);
    }

    /** Kode URL pendek 6 karakter, unik global (dicek tanpa tenant scope). */
    public static function newShortCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::SHORT_ALPHABET[random_int(0, strlen(self::SHORT_ALPHABET) - 1)];
            }
        } while (self::allTenants()->withoutGlobalScopes()->where('short_code', $code)->exists());

        return $code;
    }

    /** URL pendek untuk dicetak di kartu & dibagikan; dialihkan ke orderUrl(). */
    public function shortUrl(): string
    {
        return route('self-order.short', ['code' => $this->short_code]);
    }

    /** URL publik untuk QR (halaman pesan, tanpa login). */
    public function orderUrl(): string
    {
        return route('self-order.table', ['token' => $this->qr_token]);
    }
}
