<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyOwnerEmail;
use App\Support\Domains;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verifikasi email owner (ADR 0009, SPEC Q31).
 */
final class EmailVerificationController extends Controller
{
    /** gspos.id/verifikasi-email/{user}/{hash}: link bertanda tangan dari email, tanpa login. */
    public function verify(Request $request, string $user, string $hash): View
    {
        // Lintas tenant: belum ada tenant context di domain utama; id dari link bertanda tangan
        $account = User::allTenants()->find($user);

        abort_if($account === null || ! hash_equals(sha1((string) $account->email), $hash), 403, 'Link verifikasi tidak valid.');

        if (! $account->hasVerifiedEmail()) {
            $account->forceFill(['email_verified_at' => now()])->saveQuietly();
        }

        return view('site.email-verified', [
            'name' => $account->name,
            'continueUrl' => Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getUrl(),
        ]);
    }

    /** app.gspos.id/verifikasi-email/kirim-ulang (login, dibatasi): kirim ulang link ke diri sendiri. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail() && filled($user->email)) {
            $user->notify(new VerifyOwnerEmail);

            Notification::make()->success()
                ->title('Link verifikasi dikirim ulang')
                ->body('Cek kotak masuk atau folder spam '.$user->email.'.')
                ->send();
        }

        return redirect()->back(fallback: Filament::getPanel('dashboard')->getUrl());
    }
}
