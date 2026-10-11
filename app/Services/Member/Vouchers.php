<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Actions\Order\Data\ResolvedLine;
use App\Enums\ErrorCode;
use App\Enums\VoucherSource;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\Order;
use App\Models\OutletMemberProgram;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Aturan pakai voucher (ADR 0013, Q66). Kode promo owner boleh tanpa member (kecuali `member_only`/kuota per
 * member); voucher pribadi (ulang tahun) hanya untuk pemiliknya. Semua voucher hanya di outlet yang ikut program.
 *
 * Validasi dilakukan saat harga dihitung (checkout, open bill, pratinjau) dan diulang dengan baris voucher terkunci
 * saat order selesai, sehingga kuota tidak terlampaui oleh transaksi bersamaan. Gagal = `422 VOUCHER_INVALID`
 * dengan `details.reason`.
 */
final class Vouchers
{
    /** Format kode promo: huruf besar, angka, tanda hubung. */
    public const CODE_PATTERN = '/^[A-Z0-9-]{3,30}$/';

    /**
     * Voucher yang dipilih (kode atau ID) sudah divalidasi untuk member, outlet, dan subtotal ini.
     *
     * @param  list<ResolvedLine>  $lines
     *
     * @throws BusinessException VOUCHER_INVALID
     */
    public function resolve(?string $code, ?string $voucherId, ?Member $member, string $outletId, array $lines, ?CarbonImmutable $now = null): ?Voucher
    {
        if ($code === null && $voucherId === null) {
            return null;
        }

        $voucher = $voucherId !== null
            ? Voucher::query()->find($voucherId)
            : Voucher::query()->where('code', Voucher::normalizeCode($code))->first();

        if ($voucher === null) {
            self::fail('not_found', 'Kode voucher tidak ditemukan');
        }

        $this->validate($voucher, $member, $outletId, self::subtotal($lines), $now);

        return $voucher;
    }

    /** @throws BusinessException VOUCHER_INVALID */
    public function validate(Voucher $voucher, ?Member $member, string $outletId, string $subtotal, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $usableMember = $member !== null && $member->isUsable() ? $member : null;

        match (true) {
            ! $voucher->is_active => self::fail('inactive', 'Voucher sudah tidak aktif'),
            $voucher->member_id !== null && $voucher->member_id !== $usableMember?->id => self::fail('not_owner', 'Voucher ini milik member lain'),
            ! OutletMemberProgram::activeAt($outletId),
            $voucher->outlet_ids !== null && $voucher->outlet_ids !== [] && ! in_array($outletId, $voucher->outlet_ids, true) => self::fail('outlet', 'Voucher tidak berlaku di outlet ini'),
            $voucher->starts_at !== null && $voucher->starts_at->greaterThan($now) => self::fail('not_started', 'Voucher belum berlaku'),
            $voucher->ends_at !== null && $voucher->ends_at->lessThan($now) => self::fail('expired', 'Voucher sudah kedaluwarsa'),
            ($voucher->member_only || $voucher->per_member_quota !== null) && $usableMember === null => self::fail('member_only', 'Voucher ini khusus member; pasang member dulu'),
            $voucher->tier_ids !== null && $voucher->tier_ids !== [] && ! in_array($usableMember?->tier_id, $voucher->tier_ids, true) => self::fail('tier', 'Voucher tidak berlaku untuk tier member ini'),
            Money::compare($subtotal, $voucher->min_spend) < 0 => self::fail('min_spend', 'Minimal belanja '.Money::format($voucher->min_spend).' untuk voucher ini', ['min_spend' => $voucher->min_spend]),
            default => null,
        };

        $used = VoucherRedemption::query()->where('voucher_id', $voucher->id)->where('status', VoucherRedemption::USED);

        if ($voucher->quota !== null && (clone $used)->count() >= $voucher->quota) {
            self::fail('quota_exhausted', 'Kuota voucher sudah habis');
        }

        if ($voucher->per_member_quota !== null && (clone $used)->where('member_id', $usableMember->id)->count() >= $voucher->per_member_quota) {
            self::fail('member_quota_exhausted', 'Member ini sudah memakai voucher ini');
        }
    }

    /**
     * Voucher pribadi member yang masih bisa dipakai (ulang tahun), untuk kasir & halaman member.
     *
     * @return Collection<int, Voucher>
     */
    public function personalFor(Member $member, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return Voucher::query()->where('member_id', $member->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->whereDoesntHave('redemptions', fn ($q) => $q->where('status', VoucherRedemption::USED))
            ->orderBy('ends_at')->get();
    }

    /**
     * Catat pemakaian voucher order yang SELESAI (di dalam transaksi order). Baris voucher dikunci & kuota dicek
     * ulang; idempotent per order.
     *
     * @throws BusinessException VOUCHER_INVALID kuota habis karena transaksi lain lebih dulu
     */
    public function redeem(Order $order): void
    {
        if ($order->voucher_id === null) {
            return;
        }

        $voucher = Voucher::query()->lockForUpdate()->find($order->voucher_id);

        if ($voucher === null || VoucherRedemption::query()->where('voucher_id', $voucher->id)->where('order_id', $order->id)->exists()) {
            return;
        }

        $used = VoucherRedemption::query()->where('voucher_id', $voucher->id)->where('status', VoucherRedemption::USED);
        if ($voucher->quota !== null && (clone $used)->count() >= $voucher->quota) {
            self::fail('quota_exhausted', 'Kuota voucher sudah habis');
        }
        if ($voucher->per_member_quota !== null && (clone $used)->where('member_id', $order->member_id)->count() >= $voucher->per_member_quota) {
            self::fail('member_quota_exhausted', 'Member ini sudah memakai voucher ini');
        }

        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'member_id' => $order->member_id,
            'order_id' => $order->id,
            'outlet_id' => $order->outlet_id,
            'amount' => $order->voucher_discount,
            'status' => VoucherRedemption::USED,
        ]);
        $voucher->forceFill(['used_count' => $voucher->used_count + 1])->save();
    }

    /** Void: pemakaian dibatalkan, kuota kembali. Idempotent. */
    public function reverse(Order $order): void
    {
        $redemption = VoucherRedemption::query()->where('order_id', $order->id)->where('status', VoucherRedemption::USED)->first();

        if ($redemption === null) {
            return;
        }

        $voucher = Voucher::query()->lockForUpdate()->find($redemption->voucher_id);
        $redemption->forceFill(['status' => VoucherRedemption::VOIDED])->save();
        $voucher?->forceFill(['used_count' => max(0, $voucher->used_count - 1)])->save();
    }

    public static function isPersonal(Voucher $voucher): bool
    {
        return $voucher->source === VoucherSource::Birthday || $voucher->member_id !== null;
    }

    /**
     * Subtotal setelah diskon item (dasar minimal belanja), rumus sama dengan OrderCalculator langkah 1–2.
     *
     * @param  list<ResolvedLine>  $lines
     */
    public static function subtotal(array $lines): string
    {
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $gross = Money::mul(Money::add($line->product->price, $line->optionsTotal), $line->qty);
            $subtotal = Money::add($subtotal, Money::sub($gross, Money::min(Money::max($line->discount, '0.00'), $gross)));
        }

        return $subtotal;
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws BusinessException
     */
    private static function fail(string $reason, string $message, array $extra = []): never
    {
        throw BusinessException::of(ErrorCode::VoucherInvalid, $message, ['reason' => $reason, ...$extra]);
    }
}
