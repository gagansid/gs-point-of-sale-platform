<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Domains;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * URL lama di domain utama → subdomain baru (ADR 0008), path & query dipertahankan. Tujuan diatur lewat route
 * defaults (`target`, `prefix`, `status`) — bukan closure, karena closure bersarang rusak saat `route:cache`
 * (`php artisan optimize` di produksi).
 */
final class MoveToSubdomainController extends Controller
{
    /** Kunci `target` → domain tujuan. */
    private const TARGETS = ['app', 'admin', 'api'];

    public function __invoke(Request $request): RedirectResponse
    {
        $route = $request->route();
        $target = (string) $route?->parameter('target');
        abort_unless(in_array($target, self::TARGETS, true), 404);

        $domain = (string) match ($target) {
            'app' => Domains::app(),
            'admin' => Domains::admin(),
            'api' => Domains::api(),
        };
        $path = (string) $route?->parameter('prefix').(string) $route?->parameter('path');

        // 301 untuk halaman, 308 untuk API (metode & body POST ikut dialihkan)
        $status = $route?->parameter('status') === '308' ? 308 : 301;

        return redirect()->away(Domains::url($domain, $path, $request->getQueryString()), $status);
    }
}
