<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Auth\Data\ApprovalData;
use App\Actions\Auth\VerifyApproval;
use App\Actions\Order\Data\ResolvedLine;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Order\CalculationResult;
use App\Services\Order\CalculatorLine;
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
    public function handle(User $actor, Outlet $outlet, array $lines, ?DiscountType $discountType, string $discountValue, ?ApprovalData $approval): array
    {
        $totals = $this->calculator->calculate(
            array_map(fn (ResolvedLine $l): CalculatorLine => new CalculatorLine($l->product->price, $l->optionsTotal, $l->qty, $l->discount), $lines),
            $discountType,
            $discountValue,
            $outlet->service_charge_rate,
            $outlet->tax_rate,
            $outlet->tax_inclusive,
            $outlet->rounding,
        );

        return ['totals' => $totals, 'approved_by' => $this->checkDiscountLimit($actor, $outlet, $totals, $approval)];
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
        if (! Money::isPositive($totals->discountTotal)) {
            return null;
        }

        /** @var array<string, int|float|string> $limits */
        $limits = $outlet->discount_limits ?? config('pos.outlet_defaults.discount_limits');
        $percent = $totals->discountPercent();
        $limitOf = fn (User $user): string => (string) ($limits[$user->role->value] ?? 0);
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
}
