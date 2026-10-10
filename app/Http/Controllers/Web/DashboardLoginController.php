<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Auth\DashboardLoginTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\DashboardLoginRequest;
use App\Models\User;
use App\Support\Domains;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login pelanggan di gspos.id/login → app.gspos.id lewat tiket sekali pakai (ADR 0008).
 * Domain utama tidak membuat sesi login sendiri; ia hanya memverifikasi kata sandi.
 */
final class DashboardLoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(Request $request): View
    {
        return view('site.login', [
            'next' => DashboardLoginTicket::safePath($request->query('next')),
            'expired' => $request->boolean('expired'),
        ]);
    }

    public function store(DashboardLoginRequest $request, DashboardLoginTicket $tickets): RedirectResponse
    {
        $email = Str::lower($request->string('email')->trim()->toString());
        $throttleKey = 'dashboard-login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        // Lintas tenant (email unik global): tenant baru diketahui setelah user ditemukan. Tidak membuat
        // sesi login di domain utama — hanya memverifikasi kata sandi.
        $user = User::allTenants()->with('tenant')->where('email', $email)->first();

        // Hash tetap dicek walau user tidak ada agar waktu respons sama (anti enumerasi akun)
        $passwordValid = Hash::check($request->string('password')->toString(), $user->password ?? self::dummyHash());

        if ($user === null || ! $passwordValid) {
            RateLimiter::hit($throttleKey, 60);

            // Pesan umum: tidak membedakan email tidak terdaftar dan kata sandi salah
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        RateLimiter::clear($throttleKey);

        if (! $user->canAccessPanel(Filament::getPanel('dashboard'))) {
            throw ValidationException::withMessages(['email' => 'Akun ini tidak memiliki akses ke dashboard.']);
        }

        if (! $user->tenant->isActive()) {
            throw ValidationException::withMessages(['email' => 'Langganan bisnis Anda tidak aktif. Hubungi tim gs.POS.']);
        }

        $ticket = $tickets->issue($user, $request->boolean('remember'), DashboardLoginTicket::safePath($request->input('next')));

        return redirect()->away(Domains::url((string) Domains::app(), 'auth/ticket', http_build_query(['ticket' => $ticket])), 303);
    }

    /** app.gspos.id/auth/ticket: tukar tiket menjadi sesi login di subdomain app. */
    public function redeem(Request $request, DashboardLoginTicket $tickets): RedirectResponse
    {
        $result = $tickets->redeem((string) $request->query('ticket', ''));

        if ($result === null) {
            return redirect()->away(self::centralLoginUrl(['expired' => 1]));
        }

        Auth::guard('web')->login($result['user'], $result['remember']);
        $request->session()->regenerate();

        return redirect()
            ->to($result['next'] ?? Filament::getPanel('dashboard')->getUrl())
            ->header('Cache-Control', 'no-store');
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('dummy-password-untuk-waktu-konstan');
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public static function centralLoginUrl(array $query = []): string
    {
        return Domains::url((string) Domains::main(), 'login', $query === [] ? null : http_build_query($query));
    }
}
