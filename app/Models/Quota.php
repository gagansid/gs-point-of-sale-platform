<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data kuota paket langganan (ADR 0025 B1). Data platform (tanpa tenant_id), dikelola langsung di database
 * (tanpa CRUD). `key` stabil: cara menghitung pemakaian ada di App\Support\Quotas; key tanpa penghitung hanya label.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 */
final class Quota extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * Teks batas untuk tampilan, urut master data: "Outlet aktif 3 · Produk aktif 200".
     *
     * @param  array<string, int|null>  $limits
     */
    public static function summary(array $limits, string $empty = 'Tanpa batas'): string
    {
        $parts = [];
        foreach (self::options() as $key => $name) {
            if (($limits[$key] ?? null) !== null) {
                $parts[] = $name.' '.number_format((int) $limits[$key], 0, ',', '.');
            }
        }

        return $parts === [] ? $empty : implode(' · ', $parts);
    }

    /** @return array<string, string> key => nama, urut sort_order (form paket & tenant, pesan error) */
    public static function options(): array
    {
        return once(fn (): array => self::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'key')->all());
    }
}
