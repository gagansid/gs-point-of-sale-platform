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
     */
    private function checkDiscountLimit(User $actor, Outlet $outlet, CalculationResult $totals, ?ApprovalData $approval): ?string
    {
        if (! Money::isPositive($totals->discountTotal)) {
            return null;
        }

        $limits = $outlet->discount_limits ?? config('pos.outlet_defaults.discount_limits');
        $limit = (string) ($limits[$actor->role->value] ?? 0);
        $percent = $totals->discountPercent();

        if (bccomp($percent, $limit, 2) <= 0) {
            return null;
        }

        return $this->verifyApproval->handle(
            $actor,
            'order.discount_over_limit',
            $approval,
            ErrorCode::DiscountOverLimit,
            ['limit_percent' => $limit, 'discount_percent' => $percent],
        );
    }
}
