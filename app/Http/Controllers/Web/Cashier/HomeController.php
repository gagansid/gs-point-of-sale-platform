<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Http\Controllers\Controller;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Beranda kasir web = layar transaksi; tanpa shift terbuka diarahkan ke buka shift. */
final class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $shift = ShiftController::openShift($request);

        if ($shift === null) {
            return redirect()->route('cashier.shift');
        }

        return view('cashier.home', [
            'user' => WebCashier::user(),
            'outlet' => CurrentOutlet::getOrFail(),
            'device' => WebCashier::device(),
            'shift' => $shift,
        ]);
    }
}
