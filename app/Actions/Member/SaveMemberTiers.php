<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberTier;
use App\Services\Member\TierEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Simpan seluruh daftar tier member (ADR 0013, Q64; izin member.settings). Daftar menggantikan yang lama: baris
 * ber-`id` diubah, tanpa `id` ditambah, yang tidak dikirim dihapus. Semua member langsung dievaluasi ulang.
 */
final class SaveMemberTiers
{
    /** Batas jumlah tier per bisnis (cukup untuk Reguler/Silver/Gold/Platinum…). */
    public const MAX_TIERS = 10;

    public function __construct(private readonly TierEvaluator $evaluator) {}

    /**
     * @param  list<array<string, mixed>>  $tiers  name, min_spend_12m, point_multiplier, discount_percent, id (opsional)
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(array $tiers): void
    {
        $validated = Validator::make(['tiers' => $tiers], [
            'tiers' => ['array', 'max:'.self::MAX_TIERS],
            'tiers.*.id' => ['nullable', 'uuid'],
            ...collect(self::fieldRules())->mapWithKeys(fn (array $rules, string $field): array => ["tiers.*.{$field}" => $rules])->all(),
        ], [], [
            'tiers.*.name' => 'nama tier',
            'tiers.*.min_spend_12m' => 'minimal belanja',
            'tiers.*.point_multiplier' => 'pengali poin',
            'tiers.*.discount_percent' => 'diskon tier',
        ])->validate()['tiers'];

        self::ensureDistinct($validated);

        DB::transaction(function () use ($validated): void {
            $keep = [];

            foreach ($validated as $row) {
                // ID dari form dicari dalam tenant aktif; ID asing/terhapus = tier baru
                $tier = (isset($row['id']) ? MemberTier::query()->find($row['id']) : null) ?? new MemberTier;
                $tier->fill([
                    'name' => trim((string) $row['name']),
                    'min_spend_12m' => (string) $row['min_spend_12m'],
                    'point_multiplier' => (string) $row['point_multiplier'],
                    'discount_percent' => (string) $row['discount_percent'],
                ])->save();
                $keep[] = $tier->id;
            }

            $removed = MemberTier::query()->whereNotIn('id', $keep)->pluck('id');
            if ($removed->isNotEmpty()) {
                Member::query()->whereIn('tier_id', $removed)->update(['tier_id' => null]);
                MemberTier::query()->whereIn('id', $removed)->get()->each->delete();
            }

            $this->evaluator->refreshAll();
        });
    }

    /**
     * Aturan bersama form dashboard & Action.
     *
     * @return array<string, list<string>>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'min_spend_12m' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'point_multiplier' => ['required', 'decimal:0,2', 'min:0', 'max:10'],
            'discount_percent' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    /**
     * Nama & batas belanja tier harus berbeda (dua tier dengan batas sama membuat tier ambigu).
     *
     * @param  list<array<string, mixed>>  $tiers
     *
     * @throws BusinessException
     */
    private static function ensureDistinct(array $tiers): void
    {
        $names = array_map(fn (array $t): string => mb_strtolower(trim((string) $t['name'])), $tiers);
        $spends = array_map(fn (array $t): string => number_format((float) $t['min_spend_12m'], 2, '.', ''), $tiers);

        if (count(array_unique($names)) !== count($names)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Nama tier tidak boleh sama', ['tiers' => ['Nama tier tidak boleh sama']]);
        }

        if (count(array_unique($spends)) !== count($spends)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Minimal belanja tiap tier harus berbeda', ['tiers' => ['Minimal belanja tiap tier harus berbeda']]);
        }
    }
}
