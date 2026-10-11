<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\DiningTable;
use App\Models\Outlet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah meja (ADR 0014, Q69; izin table.manage). Nama unik per outlet; outlet harus milik tenant & bisa
 * diakses user (OutletScope lewat Outlet query di pemanggil).
 */
final class SaveDiningTable
{
    /**
     * @param  array<string, mixed>  $data  name, area, sort_order (opsional)
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?DiningTable $table, Outlet $outlet, array $data): DiningTable
    {
        $validated = Validator::make($data, self::rules(), [], ['name' => 'nama meja', 'area' => 'area'])->validate();
        $name = trim((string) $validated['name']);

        $taken = DiningTable::query()->where('outlet_id', $table->outlet_id ?? $outlet->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($table !== null, fn ($q) => $q->whereKeyNot($table->id))->exists();
        if ($taken) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nama meja sudah dipakai di outlet ini', ['name' => ['Nama meja sudah dipakai di outlet ini']]);
        }

        $table ??= new DiningTable(['outlet_id' => $outlet->id, 'sort_order' => (int) DiningTable::query()->where('outlet_id', $outlet->id)->max('sort_order') + 1]);
        $table->fill([
            'name' => $name,
            'area' => filled($validated['area'] ?? null) ? trim((string) $validated['area']) : null,
        ])->save();

        return $table;
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:50'],
        ];
    }
}
