<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Member;

use App\Actions\Member\AuthenticateMember;
use App\Actions\Member\SignUpMember;
use App\Http\Controllers\Controller;
use App\Support\MemberPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Masuk, daftar, keluar halaman member gspos.id/m/{bisnis} (ADR 0013, Q67): nomor HP + kata sandi, tanpa OTP.
 * Aturan & batas percobaan ada di AuthenticateMember / SignUpMember (dipakai juga halaman pesan meja, ADR 0027).
 */
final class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (MemberPortal::member() !== null) {
            return redirect()->route('member.home', ['business' => MemberPortal::tenant()->slug]);
        }

        return view('member.login');
    }

    public function store(Request $request, AuthenticateMember $authenticate): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:255']]);
        $tenant = MemberPortal::tenant();
        $member = $authenticate->handle($tenant, $data['phone'], $data['password'], (string) $request->ip());
        MemberPortal::login($request, $member);

        return MemberPortal::returnTo($request) ?? redirect()->route('member.home', ['business' => $tenant->slug]);
    }

    public function registerForm(): View
    {
        return view('member.register');
    }

    public function register(Request $request, SignUpMember $signUp): RedirectResponse
    {
        $member = $signUp->handle($request->only(['name', 'phone', 'password', 'password_confirmation', 'consent']), (string) $request->ip());
        MemberPortal::login($request, $member);

        return MemberPortal::returnTo($request) ?? redirect()->route('member.home', ['business' => MemberPortal::tenant()->slug])->with('status', 'Selamat bergabung!');
    }

    public function destroy(Request $request): RedirectResponse
    {
        MemberPortal::logout($request);

        return redirect()->route('member.login', ['business' => MemberPortal::tenant()->slug]);
    }
}
