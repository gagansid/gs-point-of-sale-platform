<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\VoucherSource;
use App\Exceptions\BusinessException;
use App\Models\MemberTier;
use App\Models\Outlet;
use App\Models\Voucher;
use App\Services\Member\Vouchers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah kode promo owner (ADR 0013, Q66; izin member.settings). Voucher ulang tahun dibuat sistem dan tidak
 * bisa diubah di sini. Kode unik per bisnis (huruf besar); tier & outlet dicari dalam tenant aktif.
 */
final class SaveVoucher
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws BusinessException
     */
    public function handle(?Voucher $voucher, array $data): Voucher
    {
        if ($voucher !== null && $voucher->source !== VoucherSource::Owner) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Voucher ulang tahun dibuat otomatis dan tidak bisa diubah');
        }

        $data['code'] = Voucher::normalizeCode((string) ($data['code'] ?? ''));
        $percent = ($data['type'] ?? null) === DiscountType::Percent->value || ($data['type'] ?? null) === DiscountType::Percent;
        $validated = Validator::make($data, self::rules($percent), [
            'code.regex' => 'Kode hanya huruf, angka, dan tanda hubung (3–30 karakter)',
        ], self::attributes())->validate();

        $taken = Voucher::query()->where('code', $validated['code'])->when($voucher !== null, fn ($q) => $q->whereKeyNot($voucher->id))->exists();
        if ($taken) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Kode voucher sudah dipakai', ['code' => ['Kode voucher sudah dipakai']]);
        }

        $tierIds = array_values(array_unique(array_map('strval', $validated['tier_ids'] ?? [])));
        $outletIds = array_values(array_unique(array_map('strval', $validated['outlet_ids'] ?? [])));

        // Fail-closed: ID di luar tenant aktif tidak ditemukan
        if (MemberTier::query()->whereIn('id', $tierIds)->count() !== count($tierIds)
            || Outlet::query()->whereIn('id', $outletIds)->count() !== count($outletIds)) {
            throw BusinessException::of(ErrorCode::ValidationError, 'Tier atau outlet tidak ditemukan', ['tier_ids' => ['Tier atau outlet tidak ditemukan']]);
        }

        $voucher ??= new Voucher;
        $voucher->fill([
            'code' => $validated['code'],
            'name' => trim((string) $validated['name']),
            'type' => DiscountType::from((string) ($validated['type'] instanceof DiscountType ? $validated['type']->value : $validated['type'])),
            'value' => (string) $validated['value'],
            'max_discount' => $percent && filled($validated['max_discount'] ?? null) ? (string) $validated['max_discount'] : null,
            'min_spend' => (string) ($validated['min_spend'] ?? '0'),
            'quota' => filled($validated['quota'] ?? null) ? (int) $validated['quota'] : null,
            'per_member_quota' => filled($validated['per_member_quota'] ?? null) ? (int) $validated['per_member_quota'] : null,
            'starts_at' => filled($validated['starts_at'] ?? null) ? CarbonImmutable::parse($validated['starts_at'])->utc() : null,
            'ends_at' => filled($validated['ends_at'] ?? null) ? CarbonImmutable::parse($validated['ends_at'])->utc() : null,
            // Syarat tier/kuota per member hanya bisa dicek bila ada member
            'member_only' => (bool) ($validated['member_only'] ?? false) || $tierIds !== [] || filled($validated['per_member_quota'] ?? null),
            'tier_ids' => $tierIds !== [] ? $tierIds : null,
            'outlet_ids' => $outletIds !== [] ? $outletIds : null,
            ...array_key_exists('is_active', $validated) ? ['is_active' => (bool) $validated['is_active']] : [],
        ])->save();

        return $voucher;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(bool $percent = false): array
    {
        $money = ['decimal:0,2', 'min:0', 'max:999999999.99'];

        return [
            'code' => ['required', 'string', 'regex:'.Vouchers::CODE_PATTERN],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'decimal:0,2', 'gt:0', $percent ? 'max:100' : 'max:999999999.99'],
            'max_discount' => ['nullable', ...$money],
            'min_spend' => ['nullable', ...$money],
            'quota' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_member_quota' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'member_only' => ['sometimes', 'boolean'],
            'tier_ids' => ['nullable', 'array', 'max:10'],
            'tier_ids.*' => ['uuid'],
            'outlet_ids' => ['nullable', 'array', 'max:100'],
            'outlet_ids.*' => ['uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private static function attributes(): array
    {
        return [
            'code' => 'kode', 'name' => 'nama', 'type' => 'jenis', 'value' => 'nilai', 'max_discount' => 'maks. potongan',
            'min_spend' => 'minimal belanja', 'quota' => 'kuota', 'per_member_quota' => 'kuota per member',
            'starts_at' => 'mulai', 'ends_at' => 'berakhir',
        ];
    }
}
