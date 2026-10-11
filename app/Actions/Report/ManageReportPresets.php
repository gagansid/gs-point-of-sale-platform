<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\ReportPreset;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Preset filter laporan per user (R8). Hanya kunci filter yang dikenal yang disimpan; nilainya
 * dinormalkan lagi saat dipakai (batas 31 hari supervisor, ID tenant lain = hasil kosong).
 */
final class ManageReportPresets
{
    public const MAX_PER_USER = 10;

    /** Kunci filter yang disimpan; outlet sengaja tidak (mengikuti pemilih outlet sidebar). */
    public const KEYS = ['preset', 'from', 'to', 'compare', 'granularity', 'order_type', 'payment_method_ids', 'user_ids', 'category_ids', 'product_ids', 'shift_ids', 'tab'];

    /**
     * Simpan atau timpa preset bernama sama.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws BusinessException
     */
    public function save(User $user, string $name, array $filters): ReportPreset
    {
        $name = trim(mb_substr($name, 0, 60));

        if ($name === '') {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nama preset wajib diisi', ['name' => ['Nama preset wajib diisi']]);
        }

        return DB::transaction(function () use ($user, $name, $filters): ReportPreset {
            $existing = ReportPreset::query()->where('user_id', $user->id)->where('name', $name)->lockForUpdate()->first();

            if ($existing === null && ReportPreset::query()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Maksimal '.self::MAX_PER_USER.' preset. Hapus preset lama dulu.');
            }

            $preset = $existing ?? new ReportPreset(['user_id' => $user->id, 'name' => $name]);
            $preset->filters = Arr::only($filters, self::KEYS);
            $preset->save();

            return $preset;
        });
    }

    /** Hanya pemilik yang bisa menghapus; preset user lain = tidak ditemukan. */
    public function delete(User $user, string $presetId): void
    {
        ReportPreset::query()->where('user_id', $user->id)->whereKey($presetId)->delete();
    }

    /** @return array<string, mixed>|null */
    public function filtersOf(User $user, string $presetId): ?array
    {
        $preset = ReportPreset::query()->where('user_id', $user->id)->find($presetId);

        return $preset?->filters;
    }
}
