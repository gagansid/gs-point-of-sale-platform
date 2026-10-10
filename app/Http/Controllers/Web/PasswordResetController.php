<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Auth\ResetPasswordWithToken;
use App\Http\Controllers\Controller;
use App\Support\Domains;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * gspos.id/forgot-password & /reset-password/{token} untuk owner/manager (SPEC Q34).
 * Kasir/supervisor tanpa email meminta owner mengganti kata sandinya di menu Karyawan.
 */
final class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('site.forgot-password', ['loginUrl' => self::loginUrl()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']], attributes: ['email' => 'email']);

        // Hasil (terdaftar/tidak/terlalu sering) tidak dibedakan: cegah enumerasi akun
        Password::broker('users')->sendResetLink(['email' => $request->string('email')->toString()]);

        return back()->with('status', 'Jika email terdaftar, link atur ulang kata sandi sudah kami kirim. Cek juga folder spam.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('site.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'invitation' => $request->boolean('invite'),
        ]);
    }

    public function update(Request $request, ResetPasswordWithToken $action): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordRule::defaults()],
            'invitation' => ['sometimes', 'boolean'],
        ], attributes: ['password' => 'kata sandi']);

        $status = $action->handle($data['email'], $data['token'], $data['password'], $request->boolean('invitation'));

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Link tidak valid atau sudah kedaluwarsa. Minta link baru lewat "Lupa kata sandi".',
            ]);
        }

        return redirect()->to(self::loginUrl())->with('status', 'Kata sandi berhasil disimpan. Silakan masuk.');
    }

    private static function loginUrl(): string
    {
        return Domains::enabled() ? route('login') : Filament::getPanel('dashboard')->getLoginUrl();
    }
}
