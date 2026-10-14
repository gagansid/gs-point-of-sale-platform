<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Cashier\UnlockWebCashier;
use App\Http\Controllers\Controller;
use App\Support\CurrentOutlet;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kunci layar kasir web (ADR 0017): tombol Kunci / kunci otomatis → layar PIN; karyawan lain di outlet ini masuk
 * dengan PIN dan melanjutkan shift yang sama tanpa menghitung uang. Selama terkunci semua halaman kasir
 * diarahkan ke sini (SetCashierContext).
 */
final class LockController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->session()->put(WebCashier::SESSION_LOCKED, true);

        return redirect()->route('cashier.unlock');
    }

    public function show(Request $request): View|RedirectResponse
    {
        if (! WebCashier::isLocked($request)) {
            return redirect()->route('cashier.home');
        }

        return view('cashier.unlock', [
            'users' => UnlockWebCashier::users(WebCashier::device()),
            'current' => WebCashier::user(),
            'outlet' => CurrentOutlet::getOrFail(),
        ]);
    }

    public function unlock(Request $request, UnlockWebCashier $unlock): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'pin' => ['required', 'string', 'digits:6'],
        ], [], ['user_id' => 'nama', 'pin' => 'PIN']);

        $user = $unlock->handle(WebCashier::device(), (string) $data['user_id'], (string) $data['pin']);

        // Sesi tetap (perangkat & transaksi tertahan di browser ini), identitas kasir berganti
        WebCashier::guard()->login($user);
        $request->session()->regenerate();
        $request->session()->put(WebCashier::SESSION_PASSWORD, WebCashier::passwordFingerprint($user));
        $request->session()->forget(WebCashier::SESSION_LOCKED);

        return redirect()->route('cashier.home')->with('status', 'Halo, '.$user->name.'. Shift dilanjutkan.');
    }
}
