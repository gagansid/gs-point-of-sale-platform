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

    /** Masuk member lalu kembali ke meja ini (sesi halaman member, ADR 0013). */
    public function member(): RedirectResponse
    {
        $context = SelfOrderContext::get();
        session()->put('member.return_to', route('self-order.table', ['token' => $context->table->qr_token]));

        return redirect()->route('member.login', ['business' => $context->tenant->slug]);
    }
}
