<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris setelan sistem. Jangan dipakai langsung; gunakan App\Support\SystemSettings.
 *
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by
 */
final class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
