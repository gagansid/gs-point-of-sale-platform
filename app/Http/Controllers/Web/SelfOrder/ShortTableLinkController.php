<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SelfOrder;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;

/**
 * URL pendek kartu meja gspos.id/t/{kode} (ADR 0014): dialihkan ke URL asli /order/t/{qr_token}. Kode tidak dikenal /
 * meja nonaktif → 404 (pencarian lintas tenant hanya lewat kode acak unik global).
 */
final class ShortTableLinkController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        $table = DiningTable::allTenants()->withoutGlobalScopes()
            ->where('short_code', mb_strtoupper($code))->where('is_active', true)->first();

        abort_if($table === null, 404);

        return redirect()->to(route('self-order.table', ['token' => $table->qr_token]));
    }
}
