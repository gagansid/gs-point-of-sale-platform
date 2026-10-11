<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Cashier\ConfirmWebCashierUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\CashierConfirmRequest;
use App\Models\User;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Menu Kasir di sidebar dashboard (Q55): user /dashboard masuk ulang ke kasir web dengan kata sandi
 * atau PIN. Tanpa sesi dashboard → form login kasir biasa. Tidak ada link lupa kata sandi/PIN:
 * akun terkunci dibuka owner di dashboard (menu Karyawan).
 */
final class ConfirmController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(Request $request): View|RedirectResponse
    {
        $user = self::dashboardUser();

        if ($user === null) {
            return redirect()->route('cashier.login');
        }

        if (WebCashier::user()?->is($user)) {
            return redirect()->route('cashier.home');
        }

        $hasPin = $user->pin !== null;
        $method = $request->query('method', old('method')) === ConfirmWebCashierUser::METHOD_PIN && $hasPin
            ? ConfirmWebCashierUser::METHOD_PIN
            : ConfirmWebCashierUser::METHOD_PASSWORD;

        return view('cashier.confirm', ['user' => $user, 'method' => $method, 'hasPin' => $hasPin]);
    }

    public function store(CashierConfirmRequest $request, ConfirmWebCashierUser $confirm): RedirectResponse
    {
        $user = self::dashboardUser();

        if ($user === null) {
            return redirect()->route('cashier.login')->with('status', 'Sesi dashboard berakhir. Silakan masuk lagi.');
        }

        $isPin = $request->loginMethod() === ConfirmWebCashierUser::METHOD_PIN;
        // Batas kata sandi per user + IP; PIN dibatasi VerifyPin (per sumber & kunci global, pesan "terkunci")
        $throttleKey = 'cashier-confirm:'.$user->id.'|'.$request->ip();

        if (! $isPin && RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'secret' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        try {
            $confirm->handle($user, $request->loginMethod(), $request->string('secret')->toString(), (string) $request->ip());
        } catch (ValidationException $e) {
            if (! $isPin) {
                RateLimiter::hit($throttleKey, 60);
            }

            throw $e;
        }

        if (! $isPin) {
            RateLimiter::clear($throttleKey);
        }

        // Sama dengan LoginController::store: sesi /dashboard di browser ini tetap login
        WebCashier::guard()->login($user);
        $request->session()->regenerate();
        $request->session()->forget(WebCashier::SESSION_DEVICE);
        $request->session()->put(WebCashier::SESSION_PASSWORD, WebCashier::passwordFingerprint($user));

        return redirect()->route('cashier.outlet');
    }

    /** User sesi /dashboard (guard "web") di browser yang sama. */
    private static function dashboardUser(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
