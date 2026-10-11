<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Cashier\LoginWebCashier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\CashierLoginRequest;
use App\Support\WebCashier;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Login & logout kasir web /cashier (Q54). */
final class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /** Batas kedua per IP (password spraying ke banyak username), SPEC Keamanan */
    private const MAX_ATTEMPTS_PER_IP = 20;

    public function create(Request $request): View|RedirectResponse
    {
        if (WebCashier::user() !== null) {
            return redirect()->route('cashier.home');
        }

        $business = $request->cookie(WebCashier::BUSINESS_COOKIE);

        return view('cashier.login', [
            // Tab cara masuk tidak di URL: input lama, atau username bila browser pernah memakai kode bisnis
            'mode' => old('mode', is_string($business) ? 'username' : 'email') === 'username' ? 'username' : 'email',
            'business' => old('business', is_string($business) ? $business : ''),
        ]);
    }

    public function store(CashierLoginRequest $request, LoginWebCashier $login): RedirectResponse
    {
        $identity = Str::lower(trim(($request->business() ?? '').'|'.$request->string('login')));
        $throttleKey = 'cashier-login:'.$identity.'|'.$request->ip();
        $ipKey = 'cashier-login-ip:'.$request->ip();

        foreach ([$throttleKey => self::MAX_ATTEMPTS, $ipKey => self::MAX_ATTEMPTS_PER_IP] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'login' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
                ]);
            }
        }

        try {
            $user = $login->handle($request->business(), $request->string('login')->toString(), $request->string('password')->toString());
        } catch (ValidationException $e) {
            RateLimiter::hit($throttleKey, 60);
            RateLimiter::hit($ipKey, 60);

            throw $e;
        }

        RateLimiter::clear($throttleKey);

        WebCashier::guard()->login($user);
        $request->session()->regenerate();
        $request->session()->forget(WebCashier::SESSION_DEVICE);
        $request->session()->put(WebCashier::SESSION_PASSWORD, WebCashier::passwordFingerprint($user));

        if ($request->business() !== null) {
            Cookie::queue(WebCashier::BUSINESS_COOKIE, Str::lower(trim($request->business())), 60 * 24 * 365);
        }

        return redirect()->route('cashier.outlet');
    }

    /** Keluar kasir web → kembali ke dashboard bila sesi /dashboard masih aktif (datang dari menu Kasir, Q55). */
    public function destroy(Request $request): RedirectResponse
    {
        WebCashier::logout($request);

        if (Auth::guard('web')->check()) {
            return redirect()->to(Filament::getPanel('dashboard')->getUrl());
        }

        return redirect()->route('cashier.login')->with('status', 'Anda sudah keluar dari kasir web.');
    }
}
