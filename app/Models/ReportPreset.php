<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Preset filter laporan milik satu user (R8).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 */
final class ReportPreset extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['user_id', 'name', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
