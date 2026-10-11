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
 * Akun Midtrans milik owner (ADR 0014, Q72). Server/Client Key terenkripsi (APP_KEY) dan tidak pernah dikirim ke
 * tampilan; dana langsung ke rekening owner.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property bool $enabled
 * @property bool $is_production
 * @property string|null $server_key
 * @property string|null $client_key
 * @property list<string>|null $enabled_payments
 * @property CarbonImmutable|null $verified_at
 */
final class PaymentGatewaySetting extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    /** Metode Snap yang bisa diizinkan owner (kode Midtrans => label). */
    public const PAYMENT_TYPES = [
        'qris' => 'QRIS',
        'gopay' => 'GoPay',
        'shopeepay' => 'ShopeePay',
        'bank_transfer' => 'Virtual account',
    ];

    protected $fillable = ['enabled', 'is_production', 'enabled_payments'];

    protected $hidden = ['server_key', 'client_key'];

    protected $attributes = ['provider' => 'midtrans', 'enabled' => false, 'is_production' => false];

    /** Kolom yang dicatat di audit log (Q58); key tidak pernah dicatat. */
    public static function auditAttributes(): array
    {
        return ['enabled', 'is_production', 'enabled_payments'];
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_production' => 'boolean',
            'server_key' => 'encrypted',
            'client_key' => 'encrypted',
            'enabled_payments' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    /** Siap menerima pembayaran: aktif dan key lengkap. */
    public function isReady(): bool
    {
        return $this->enabled && filled($this->server_key) && filled($this->client_key);
    }
}
