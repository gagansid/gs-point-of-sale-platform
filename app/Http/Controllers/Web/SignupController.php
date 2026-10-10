<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Auth\DashboardLoginTicket;
use App\Actions\Tenant\Data\SignupData;
use App\Actions\Tenant\RegisterTenant;
use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SignupRequest;
use App\Support\Domains;
use App\Support\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Daftar mandiri gspos.id/daftar (ADR 0009): tenant trial dibuat, lalu owner langsung masuk ke
 * panel (lewat tiket sekali pakai bila memakai subdomain, ADR 0008).
 */
final class SignupController extends Controller
{
    public function create(SystemSettings $settings): View
    {
        return view('site.signup', [
            'open' => $settings->signupEnabled(),
            'trialDays' => $settings->trialDays(),
            'businessTypes' => BusinessType::cases(),
            'loginUrl' => Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getLoginUrl(),
        ]);
    }

    public function store(SignupRequest $request, RegisterTenant $register, DashboardLoginTicket $tickets, SystemSettings $settings): RedirectResponse
    {
        // Bot yang mengisi honeypot diarahkan ke halaman depan tanpa membuat apa pun
        if ($request->isBot()) {
            return redirect()->route('landing');
        }

        // Ditutup admin saat form sedang terbuka: tampilkan halaman "pendaftaran ditutup"
        if (! $settings->signupEnabled()) {
            return redirect()->route('signup');
        }

        $owner = $register->handle(SignupData::fromArray($request->validated()));

        if (Domains::enabled()) {
            $ticket = $tickets->issue($owner, remember: false, next: null);

            return redirect()->away(Domains::url((string) Domains::app(), 'auth/ticket', http_build_query(['ticket' => $ticket])), 303);
        }

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()->to(Filament::getPanel('dashboard')->getUrl());
    }
}
