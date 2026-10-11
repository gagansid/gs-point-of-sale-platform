<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Models\StampProgram;
use App\Models\StampProgramItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah program kartu stempel (ADR 0013, Q65; izin member.settings): nama, jumlah stempel untuk 1 gratis,
 * produk & kategori yang memenuhi. Produk/kategori dicari dalam tenant aktif; ID asing ditolak.
 */
final class SaveStampProgram
{
    /**
     * @param  array<string, mixed>  $data  name, stamps_required, product_ids, category_ids, is_active (opsional)
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?StampProgram $program, array $data): StampProgram
    {
        $validated = Validator::make($data, self::rules(), [], [
            'name' => 'nama', 'stamps_required' => 'jumlah stempel', 'product_ids' => 'produk', 'category_ids' => 'kategori',
        ])->validate();

        $productIds = array_values(array_unique(array_map('strval', $validated['product_ids'] ?? [])));
        $categoryIds = array_values(array_unique(array_map('strval', $validated['category_ids'] ?? [])));

        if ($productIds === [] && $categoryIds === []) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Pilih minimal satu produk atau kategori', ['product_ids' => ['Pilih minimal satu produk atau kategori']]);
        }

        // Fail-closed: ID di luar tenant aktif (global scope) tidak ditemukan
        if (Product::query()->whereIn('id', $productIds)->count() !== count($productIds)
            || Category::query()->whereIn('id', $categoryIds)->count() !== count($categoryIds)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Produk atau kategori tidak ditemukan', ['product_ids' => ['Produk atau kategori tidak ditemukan']]);
        }

        return DB::transaction(function () use ($program, $validated, $productIds, $categoryIds): StampProgram {
            $program ??= new StampProgram;
            $program->fill([
                'name' => trim((string) $validated['name']),
                'stamps_required' => (int) $validated['stamps_required'],
                ...array_key_exists('is_active', $validated) ? ['is_active' => (bool) $validated['is_active']] : [],
            ])->save();

            $program->items()->delete();
            foreach ($productIds as $productId) {
                StampProgramItem::query()->create(['stamp_program_id' => $program->id, 'product_id' => $productId]);
            }
            foreach ($categoryIds as $categoryId) {
                StampProgramItem::query()->create(['stamp_program_id' => $program->id, 'category_id' => $categoryId]);
            }

            return $program->load('items');
        });
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'stamps_required' => ['required', 'integer', 'min:2', 'max:100'],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['uuid'],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
