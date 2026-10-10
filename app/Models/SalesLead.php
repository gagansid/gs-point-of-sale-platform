<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesLeadStatus;
use Database\Factories\SalesLeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Calon pelanggan dari form "Hubungi sales" (gspos.id). Data platform, bukan milik tenant,
 * sehingga tanpa BelongsToTenant — hanya diakses dari panel admin.
 *
 * @property string $id
 * @property string $name
 * @property string $business_name
 * @property string $phone
 * @property string|null $email
 * @property string|null $city
 * @property string|null $business_type
 * @property string|null $message
 * @property SalesLeadStatus $status
 * @property string|null $notes
 * @property string|null $ip_hash
 * @property Carbon $created_at
 */
final class SalesLead extends Model
{
    /** @use HasFactory<SalesLeadFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'business_name', 'phone', 'email', 'city', 'business_type', 'message', 'status', 'notes', 'ip_hash'];

    protected function casts(): array
    {
        return ['status' => SalesLeadStatus::class];
    }

    /** Nomor WhatsApp untuk tautan wa.me: 08xx → 628xx, hanya angka. */
    public function whatsappNumber(): string
    {
        $digits = (string) preg_replace('/\D/', '', $this->phone);

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
