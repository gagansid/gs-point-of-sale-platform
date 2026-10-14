<?php

declare(strict_types=1);

namespace App\Http\Middleware\Cashier;

use App\Models\Device;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setiap request kasir web (Q54): user masih boleh (aktif, akses kasir web, kata sandi tidak diganti,
 * bisnis aktif), lalu tenant & outlet dari device browser. Pakai parameter "outlet" untuk halaman
 * pilih outlet (device belum dipilih).
 */
final class SetCashierContext
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        WebCashier::setDevice(null);
        $user = WebCashier::user();

        if ($user === null) {
            return redirect()->route('cashier.login');
        }

        $reason = match (true) {
            ! hash_equals((string) $request->session()->get(WebCashier::SESSION_PASSWORD), WebCashier::passwordFingerprint($user)) => 'Kata sandi diganti. Silakan masuk lagi.',
            ! $user->canUseWebCashier() => 'Akses kasir web Anda dicabut.',
            ! $user->tenant->isActive() => 'Langganan bisnis Anda tidak aktif.',
            default => null,
        };

        if ($reason !== null) {
            WebCashier::logout($request);

            return redirect()->route('cashier.login')->with('status', $reason);
        }

        Auth::shouldUse(WebCashier::GUARD);
        TenantContext::set($user->tenant_id, readOnly: $user->tenant->isReadOnly());
        CurrentOutlet::set($user);

        $deviceId = $request->session()->get(WebCashier::SESSION_DEVICE);
        $device = is_string($deviceId) ? Device::query()->find($deviceId) : null;

        // Device dicabut, outlet dinonaktifkan, atau user dipindah outlet → pilih outlet lagi
        if ($device !== null && ($device->isRevoked() || ! $device->outletIsActive() || ! $user->canAccessOutlet($device->outlet_id))) {
            $request->session()->forget(WebCashier::SESSION_DEVICE);
            $device = null;

            if ($mode !== 'outlet') {
                return redirect()->route('cashier.outlet')->with('status', 'Outlet atau perangkat ini tidak bisa dipakai lagi. Pilih outlet.');
            }
        }

        if ($device === null) {
            return $mode === 'outlet' ? $next($request) : redirect()->route('cashier.outlet');
        }

        CurrentOutlet::set($user, $device->outlet_id);
        WebCashier::setDevice($device);

        // Layar terkunci (ADR 0017): semua halaman & aksi kasir menunggu PIN karyawan berikutnya
        if (WebCashier::isLocked($request) && ! $request->routeIs('cashier.unlock', 'cashier.unlock.store')) {
            return redirect()->route('cashier.unlock');
        }

        return $next($request);
    }
}
