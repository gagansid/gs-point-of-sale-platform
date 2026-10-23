<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Setelan self-order per outlet (ADR 0014, Q69). Tanpa baris = nonaktif.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $outlet_id
 * @property bool $enabled
 * @property int $payment_timeout_minutes
 * @property string|null $opens_at HH:MM:SS waktu lokal outlet
 * @property string|null $closes_at
 * @property bool $allow_pay_at_cashier
 * @property bool $allow_qris QRIS di HP (ADR 0027)
 * @property bool $allow_points bayar dengan poin dari HP (ADR 0027)
 * @property int $auto_complete_minutes pesanan lunas ditutup otomatis setelah ini (ADR 0027)
 * @property string|null $welcome_text
 */
final class SelfOrderSetting extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['outlet_id', 'enabled', 'payment_timeout_minutes', 'opens_at', 'closes_at', 'allow_pay_at_cashier', 'allow_qris', 'allow_points', 'auto_complete_minutes', 'welcome_text'];

    protected $attributes = ['enabled' => false, 'payment_timeout_minutes' => 15, 'allow_pay_at_cashier' => true, 'allow_qris' => true, 'allow_points' => true, 'auto_complete_minutes' => 60];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['enabled', 'payment_timeout_minutes', 'opens_at', 'closes_at', 'allow_pay_at_cashier', 'allow_qris', 'allow_points', 'auto_complete_minutes', 'welcome_text'];
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'payment_timeout_minutes' => 'integer', 'allow_pay_at_cashier' => 'boolean',
            'allow_qris' => 'boolean', 'allow_points' => 'boolean', 'auto_complete_minutes' => 'integer',
        ];
    }

    /** Setelan outlet; belum ada = model baru (tidak disimpan) dengan self-order nonaktif. */
    public static function forOutlet(string $outletId): self
    {
        return self::query()->where('outlet_id', $outletId)->first() ?? new self(['outlet_id' => $outletId]);
    }

    /** Dalam jam layanan (waktu lokal outlet). Tanpa jam = selalu buka; jam tutup < buka = melewati tengah malam. */
    public function isOpenAt(CarbonImmutable $now, string $timezone): bool
    {
        if ($this->opens_at === null || $this->closes_at === null) {
            return true;
        }

        $time = $now->setTimezone($timezone)->format('H:i:s');
        $open = substr($this->opens_at, 0, 8);
        $close = substr($this->closes_at, 0, 8);

        return $open <= $close ? $time >= $open && $time < $close : $time >= $open || $time < $close;
    }
}
