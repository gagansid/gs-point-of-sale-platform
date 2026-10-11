<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Member;

use App\Actions\Member\Data\MemberData;
use App\Actions\Member\RegisterMemberAccount;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\DummyPasswordHash;
use App\Support\MemberPortal;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Masuk, daftar, keluar halaman member gspos.id/m/{bisnis} (ADR 0013, Q67): nomor HP + kata sandi, tanpa OTP.
 * Pesan umum & waktu konstan; batas 5/menit per HP+IP dan 20/menit per IP; 10 salah dalam 15 menit = kunci.
 */
final class AuthController extends Controller
{
    private const PER_PHONE = 5;

    private const PER_IP = 20;

    private const LOCK_AFTER = 10;

    private const LOCK_SECONDS = 900;

    public const INVALID = 'Nomor HP atau kata sandi salah';

    public function create(): View|RedirectResponse
    {
        if (MemberPortal::member() !== null) {
            return redirect()->route('member.home', ['business' => MemberPortal::tenant()->slug]);
        }

        return view('member.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:255']]);
        $tenant = MemberPortal::tenant();
        $phone = Phone::normalize($data['phone']) ?? mb_strtolower(trim($data['phone']));
        $keys = [
            'member-login:'.$tenant->id.'|'.$phone.'|'.$request->ip() => self::PER_PHONE,
            'member-login-ip:'.$request->ip() => self::PER_IP,
        ];
        $lockKey = 'member-login-lock:'.$tenant->id.'|'.$phone;

        foreach ([...$keys, $lockKey => self::LOCK_AFTER] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['phone' => 'Terlalu banyak percobaan. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.']);
            }
        }

        // Hash dummy di semua jalur: waktu respons sama untuk nomor terdaftar maupun tidak
        $dummy = DummyPasswordHash::get();
        $member = Member::query()->where('phone', $phone)->first();
        $hash = $member?->getAttribute('password');
        $valid = Hash::check($data['password'], is_string($hash) ? $hash : $dummy);

        if ($member === null || ! is_string($hash) || ! $valid || ! $member->isUsable()) {
            foreach ($keys as $key => $max) {
                RateLimiter::hit($key, 60);
            }
            RateLimiter::hit($lockKey, self::LOCK_SECONDS);

            throw ValidationException::withMessages(['phone' => self::INVALID]);
        }

        RateLimiter::clear(array_key_first($keys));
        RateLimiter::clear($lockKey);
        MemberPortal::login($request, $member);

        return self::afterLogin($request) ?? redirect()->route('member.home', ['business' => $tenant->slug]);
    }

    public function registerForm(): View
    {
        return view('member.register');
    }

    public function register(Request $request, RegisterMemberAccount $register): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'consent' => ['accepted'],
        ], [], ['name' => 'nama', 'phone' => 'nomor HP', 'password' => 'kata sandi', 'consent' => 'persetujuan data']);

        // Batasi pendaftaran per IP (cegah pembuatan akun massal)
        $key = 'member-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['phone' => 'Terlalu banyak pendaftaran. Coba lagi nanti.']);
        }
        RateLimiter::hit($key, 3600);

        try {
            $member = $register->handle(MemberData::fromArray([...$data, 'consent' => true]), $data['password']);
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(is_array($e->details) ? $e->details : ['phone' => $e->getMessage()]);
        }

        MemberPortal::login($request, $member);

        return self::afterLogin($request) ?? redirect()->route('member.home', ['business' => MemberPortal::tenant()->slug])->with('status', 'Selamat bergabung!');
    }

    public function destroy(Request $request): RedirectResponse
    {
        MemberPortal::logout($request);

        return redirect()->route('member.login', ['business' => MemberPortal::tenant()->slug]);
    }

    /** Kembali ke halaman pesan meja (ADR 0014) bila login dimulai dari sana; hanya URL /order/t/ milik kita. */
    private static function afterLogin(Request $request): ?RedirectResponse
    {
        $target = $request->session()->pull('member.return_to');

        return is_string($target) && str_starts_with($target, url('/order/t/')) ? redirect()->to($target) : null;
    }
}
