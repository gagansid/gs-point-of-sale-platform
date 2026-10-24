<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\User\UpdateOwnAvatar;
use App\Actions\User\UpdateOwnProfile;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SecurePin;
use App\Support\WebCashier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Kasir web → Profil: data diri (foto, nama, No. HP) dan keamanan (kata sandi, PIN) milik pengguna yang masuk.
 * Email & username diatur owner (Karyawan). Ganti kata sandi/PIN wajib kata sandi saat ini; kata sandi baru mengeluarkan
 * kasir web di browser lain & aplikasi kasir, browser ini tetap masuk. Penyimpanan lewat Action yang sama dengan
 * halaman Profil dashboard.
 */
final class ProfileController extends Controller
{
    public function show(): View
    {
        $user = self::user();

        return view('cashier.profile', [
            'user' => $user,
            'avatarUrl' => $user->avatar_path !== null ? Storage::disk('public')->url($user->avatar_path) : null,
            'outlets' => $user->accessibleOutlets()->orderBy('name')->pluck('name')->implode(', '),
        ]);
    }

    public function update(Request $request, UpdateOwnProfile $profile, UpdateOwnAvatar $avatar): RedirectResponse
    {
        $user = self::user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@]/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
            'remove_avatar' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'phone' => 'nomor HP', 'avatar' => 'foto profil']);

        try {
            $user = $profile->handle($user, trim((string) $data['name']), null, phone: filled($data['phone'] ?? null) ? (string) $data['phone'] : null);
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(is_array($e->details) ? $e->details : ['name' => $e->getMessage()]);
        }

        // Nama file acak dari isi (hashName), di folder tenant pengguna
        if ($request->hasFile('avatar')) {
            $avatar->handle($user, $request->file('avatar')->store(UpdateOwnAvatar::directory($user), 'public') ?: null);
        } elseif ($request->boolean('remove_avatar')) {
            $avatar->handle($user, null);
        }

        return redirect()->route('cashier.profile')->with('status', 'Profil disimpan');
    }

    public function security(Request $request, UpdateOwnProfile $profile): RedirectResponse
    {
        $user = self::user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'pin' => ['nullable', 'string', 'digits:6', new SecurePin],
        ], [], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru', 'pin' => 'PIN baru']);

        if (blank($data['password'] ?? null) && blank($data['pin'] ?? null)) {
            throw ValidationException::withMessages(['password' => 'Isi kata sandi baru atau PIN baru']);
        }

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah']);
        }

        $user = $profile->handle($user, $user->name, null,
            filled($data['password'] ?? null) ? (string) $data['password'] : null,
            filled($data['pin'] ?? null) ? (string) $data['pin'] : null);

        // Browser ini tetap masuk; sesi kasir web lain gugur karena sidik kata sandi berubah
        $request->session()->put(WebCashier::SESSION_PASSWORD, WebCashier::passwordFingerprint($user->refresh()));

        return redirect()->route('cashier.profile')->with('status', match (true) {
            filled($data['password'] ?? null) && filled($data['pin'] ?? null) => 'Kata sandi & PIN diganti',
            filled($data['password'] ?? null) => 'Kata sandi diganti',
            default => 'PIN diganti',
        });
    }

    /** User sesi kasir dimuat dengan kolom terbatas: muat ulang lengkap sebelum diubah (reset PIN, sidik kata sandi). */
    private static function user(): User
    {
        $user = WebCashier::user() ?? abort(401);

        return $user->fresh() ?? $user;
    }
}
