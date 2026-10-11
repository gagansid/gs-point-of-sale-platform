<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Actions\Order\Data\ResolvedLine;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Order\CalculationResult;
use App\Services\Order\CalculatorLine;
use App\Services\Order\MemberPricing;
use App\Services\Order\OrderCalculator;
use App\Support\Money;

/**
 * Menghitung total order (OrderCalculator) dan memeriksa batas diskon role.
 * Dipakai checkout dan open bill agar aturannya identik.
 */
final class PriceOrder
{
    public function __construct(
        private readonly OrderCalculator $calculator,
        private readonly VerifyApproval $verifyApproval,
    ) {}

    /**
     * @param  list<ResolvedLine>  $lines
     * @return array{totals: CalculationResult, approved_by: string|null}
     */
    public function handle(User $actor, Outlet $outlet, array $lines, ?DiscountType $discountType, string $discountValue, ?ApprovalData $approval, ?MemberPricing $member = null): array
    {
        $totals = $this->totals($outlet, $lines, $discountType, $discountValue, $member);

        return ['totals' => $totals, 'approved_by' => $this->checkDiscountLimit($actor, $outlet, $totals, $approval)];
    }

    /**
     * Total saja (tanpa cek batas diskon), mis. pratinjau keranjang kasir web.
     *
     * @param  list<ResolvedLine>  $lines
     *
     * @throws BusinessException
     */
    public function totals(Outlet $outlet, array $lines, ?DiscountType $discountType, string $discountValue, ?MemberPricing $member = null): CalculationResult
    {
        $totals = $this->calculator->calculate(
            array_map(fn (ResolvedLine $l): CalculatorLine => new CalculatorLine($l->product->price, $l->optionsTotal, $l->qty, $l->discount), $lines),
            $discountType,
            $discountValue,
            $outlet->service_charge_rate,
            $outlet->tax_rate,
            $outlet->tax_inclusive,
            $outlet->rounding,
            $member,
        );

        self::ensureFitsStorage($totals);

        return $totals;
    }

    /**
     * Persen total diskon terhadap harga kotor dibandingkan outlets.discount_limits[role] (Q18).
     *
     * Wewenang diskon (pelaku maupun approver): `order.discount_over_limit` = tanpa batas (owner, manager);
     * selain itu sampai batas role-nya. Di atas batas pelaku → PIN approver yang batasnya mencukupi,
     * mis. kasir 20% boleh di-approve supervisor (25%), kasir 50% wajib manager/owner (GSPOS-SEC-014).
     */
    private function checkDiscountLimit(User $actor, Outlet $outlet, CalculationResult $totals, ?ApprovalData $approval): ?string
    {
        if (! Money::isPositive($totals->manualDiscount)) {
            return null;
        }

        /** @var array<string, int|float|string> $limits */
        $limits = $outlet->discount_limits ?? config('pos.outlet_defaults.discount_limits');
        $percent = $totals->discountPercent();
        $limitOf = fn (User $user): string => self::percent($limits[$user->role->value] ?? 0);
        $authorized = fn (User $user): bool => $user->can('order.discount_over_limit')
            || ($user->can('order.discount') && bccomp($percent, $limitOf($user), 2) <= 0);

        if ($authorized($actor)) {
            return null;
        }

        return $this->verifyApproval->handle(
            $actor,
            $outlet->id,
            'order.discount_over_limit',
            $approval,
            ErrorCode::DiscountOverLimit,
            ['limit_percent' => $limitOf($actor), 'discount_percent' => $percent],
            $authorized,
        );
    }

    /**
     * Total yang melewati DECIMAL(15,2) ditolak 422 — tanpa ini MySQL strict melempar 500 dan
     * MariaDB non-strict memotong nilainya diam-diam (GSPOS-BUG-002).
     *
     * @throws BusinessException
     */
    private static function ensureFitsStorage(CalculationResult $totals): void
    {
        $amounts = [
            $totals->grossTotal, $totals->subtotal, $totals->serviceTotal, $totals->taxTotal, $totals->grandTotal,
            ...array_column($totals->lines, 'gross'), ...array_column($totals->lines, 'line_total'),
        ];

        foreach ($amounts as $amount) {
            if (Money::compare($amount, Money::MAX) > 0) {
                throw BusinessException::of(ErrorCode::ValidationError, 'Total transaksi melebihi batas', [
                    'items' => ['Total transaksi maksimal '.Money::format(Money::MAX)],
                ]);
            }
        }
    }

    /** Persen batas diskon sebagai string desimal aman untuk bcmath ("10", "12.5"); nilai rusak = 0. */
    private static function percent(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        return rtrim(rtrim(sprintf('%.2F', max(0, min(100, (float) $value))), '0'), '.');
    }
}
