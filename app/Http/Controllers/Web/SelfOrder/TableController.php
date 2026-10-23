<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SelfOrder;

use App\Http\Controllers\Controller;
use App\Support\SelfOrderContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Halaman pesan dari meja (ADR 0014, Q70): menu, keranjang, dan status pesanan (komponen Livewire). */
final class TableController extends Controller
{
    public function show(): View
    {
        $context = SelfOrderContext::get();

        return view('self-order.table', [
            'context' => $context,
            'available' => $context->unavailableReason(),
        ]);
    }

    /** Tautan lama "Masuk sebagai member" (ADR 0014) → halaman masuk di meja (ADR 0027). */
    public function member(): RedirectResponse
    {
        return redirect()->route('self-order.login', ['token' => SelfOrderContext::get()->table->qr_token, 'from' => 'cart']);
    }
}
