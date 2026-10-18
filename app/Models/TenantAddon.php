<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add-on milik bisnis (ADR 0025 C1). Satu masa aktif dengan paket tenant. `cancelling` = tetap berlaku sampai
 * `cancel_at` (akhir masa aktif), lalu dilepas (C2). Ditulis lewat App\Actions\Billing\*.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $addon_id
 * @property int $quantity
 * @property string $status
 * @property CarbonImmutable|null $cancel_at
 */
final class TenantAddon extends Model
{
    use BelongsToTenant, HasUuids, RecordsAuthor;

    public const ACTIVE = 'active';

    public const CANCELLING = 'cancelling';

    protected $fillable = ['addon_id', 'quantity'];

    protected $attributes = ['status' => self::ACTIVE, 'quantity' => 1];

    /** Pelaku tercatat bila diatur admin (Atur add-on); dari tagihan owner (C2) jejaknya ada di tagihan. */
    public static function authorModel(): string
    {
        return Admin::class;
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'cancel_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Addon, $this> */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
