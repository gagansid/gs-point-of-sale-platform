<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsChanges;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplier barang (ADR 0015, Q79): milik bisnis, dipilih di stok masuk. Tanpa utang/pembayaran supplier.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property bool $is_active
 */
final class Supplier extends Model
{
    use BelongsToTenant, HasUuids, LogsChanges, RecordsAuthor;

    protected $fillable = ['name', 'phone', 'email', 'address', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    /** Kolom yang dicatat di audit log (Q58). */
    public static function auditAttributes(): array
    {
        return ['name', 'phone', 'email', 'address', 'notes', 'is_active'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
