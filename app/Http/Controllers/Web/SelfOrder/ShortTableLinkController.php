<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SelfOrder;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * URL pendek kartu meja gspos.id/t/{kode} (ADR 0014): dialihkan ke URL asli /order/t/{qr_token}. Kode tidak dikenal /
 * meja nonaktif → halaman "Kode QR tidak aktif" 404 (pencarian lintas tenant hanya lewat kode acak unik global).
 */
final class ShortTableLinkController extends Controller
{
    public function __invoke(string $code): RedirectResponse|Response
    {
        $table = DiningTable::allTenants()->withoutGlobalScopes()
            ->where('short_code', mb_strtoupper($code))->where('is_active', true)->first();

        if ($table === null) {
            return response()->view('self-order.invalid', [], 404);
        }

        return redirect()->to(route('self-order.table', ['token' => $table->qr_token]));
    }
}
