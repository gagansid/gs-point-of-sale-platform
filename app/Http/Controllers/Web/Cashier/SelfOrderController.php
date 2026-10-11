<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Http\Controllers\Controller;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Kasir web → Pesanan meja (ADR 0014, Q71); menerima pesanan butuh shift terbuka di browser ini. */
final class SelfOrderController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        abort_unless(WebCashier::user()?->can('self_order.handle') ?? false, 403);

        if (ShiftController::openShift($request) === null) {
            return redirect()->route('cashier.shift');
        }

        return view('cashier.self-orders');
    }
}
