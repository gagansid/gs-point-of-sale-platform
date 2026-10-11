<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Http\Controllers\Controller;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Beranda kasir web: outlet & device terpilih. Shift (W3) dan layar transaksi (W4) menyusul. */
final class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('cashier.home', [
            'user' => WebCashier::user(),
            'outlet' => CurrentOutlet::getOrFail(),
            'device' => WebCashier::device($request),
        ]);
    }
}
