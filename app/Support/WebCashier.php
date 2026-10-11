<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Konteks kasir web (Q54): guard "cashier", cookie browser, dan device terpilih di sesi.
 * Device diisi middleware SetCashierContext ke atribut request.
 */
final class WebCashier
{
    public const GUARD = 'cashier';

    /** UUID acak per browser (bukan rahasia; device tetap butuh login) */
    public const BROWSER_COOKIE = 'gspos_cashier_browser';

    /** Kode bisnis terakhir, untuk mengisi form login */
    public const BUSINESS_COOKIE = 'gspos_cashier_business';

    public const SESSION_DEVICE = 'cashier.device_id';

    public const SESSION_PASSWORD = 'cashier.password_hash';

    public static function guard(): StatefulGuard
    {
        return Auth::guard(self::GUARD);
    }

    public static function user(): ?User
    {
        $user = self::guard()->user();

        return $user instanceof User ? $user : null;
    }

    public static function device(Request $request): Device
    {
        $device = $request->attributes->get('cashier.device');

        return $device instanceof Device ? $device : throw new RuntimeException('Device kasir web belum dipilih');
    }

    /** Sidik kata sandi di sesi: ganti kata sandi = semua sesi kasir web keluar. */
    public static function passwordFingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->password, (string) config('app.key'));
    }

    /** Keluar dari kasir web saja; sesi /dashboard di browser yang sama tidak ikut keluar. */
    public static function logout(Request $request): void
    {
        self::guard()->logout();
        $request->session()->forget([self::SESSION_DEVICE, self::SESSION_PASSWORD]);
        $request->session()->regenerateToken();
    }
}
