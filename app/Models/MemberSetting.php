<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Setelan program member per tenant (ADR 0013). Tanpa baris = program belum aktif.
 * Aturan poin, tier, dan voucher ditambahkan di M2–M7.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $enabled
 */
final class MemberSetting extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['enabled'];

    protected $attributes = ['enabled' => false];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['enabled'];
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** Setelan tenant aktif; belum ada = model baru (tidak disimpan) dengan program nonaktif. */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }
}
