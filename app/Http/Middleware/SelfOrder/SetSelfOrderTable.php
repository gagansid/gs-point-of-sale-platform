<?php

declare(strict_types=1);

namespace App\Http\Middleware\SelfOrder;

use App\Support\SelfOrderContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman pesan dari meja gspos.id/order/t/{token} (ADR 0014): meja dari token QR (aktif), outlet aktif, bisnis
 * aktif; selain itu 404 (QR tidak valid / sudah diganti). Tenant context diisi sebelum data dibaca. Status
 * self-order (nonaktif, di luar jam, bisnis hanya-baca) ditampilkan halaman, bukan 404.
 */
final class SetSelfOrderTable
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = SelfOrderContext::resolve((string) $request->route('token'));
        abort_if($context === null, 404);
        SelfOrderContext::set($context);

        return $next($request);
    }
}
