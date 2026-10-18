<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data fitur yang bisa dibatasi paket langganan (ADR 0025). Data platform (tanpa tenant_id), dikelola langsung
 * di database (tanpa CRUD). `key` stabil: dipakai kode (App\Support\Features, middleware feature:{key}) dan
 * plans.features. Menghapus baris = fitur itu tidak bisa lagi dicentang di paket (tenant berpaket kehilangan aksesnya).
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 */
final class Feature extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return array<string, string> key => nama, urut sort_order (opsi form paket, label di halaman Langganan) */
    public static function options(): array
    {
        return self::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'key')->all();
    }
}
