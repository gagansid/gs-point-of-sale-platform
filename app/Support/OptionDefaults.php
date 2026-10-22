<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Option;
use App\Models\OptionGroup;

/**
 * Pilihan awal grup opsi saat produk dibuka (Q129), dipakai kasir web & self-order (aplikasi memakai
 * `options[].is_default` dari /catalog). Urutan: opsi bawaan yang tersedia (diatur owner) → untuk grup wajib tanpa
 * bawaan, opsi tersedia pertama → selain itu kosong. Opsi habis tidak pernah terpilih otomatis.
 */
final class OptionDefaults
{
    /** @return string|list<string> id opsi (pilih-satu: string, '' = kosong; pilih-banyak: daftar id) */
    public static function for(OptionGroup $group): string|array
    {
        $available = $group->options->filter(fn (Option $o): bool => (bool) ($o->getAttributes()['is_available'] ?? true));
        $defaults = $available->filter(fn (Option $o): bool => (bool) $o->is_default)->take($group->max_select)->pluck('id')->values();

        if ($defaults->isEmpty() && $group->min_select >= 1) {
            $defaults = $available->take($group->min_select)->pluck('id')->values();
        }

        return $group->max_select === 1 ? (string) ($defaults->first() ?? '') : $defaults->map(fn (mixed $id): string => (string) $id)->all();
    }
}
