<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Http\Controllers\Controller;
use App\Support\WebCashier;
use Illuminate\View\View;

/**
 * Kasir web → Antrean (ADR 0027 K8, izin order.fulfill): tidak butuh shift terbuka, karena dipakai juga tablet dapur/bar
 * yang tidak memegang laci kasir.
 */
final class QueueController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(WebCashier::user()?->can('order.fulfill') ?? false, 403);

        return view('cashier.queue');
    }
}
