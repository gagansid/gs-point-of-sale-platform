<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Konteks halaman member gspos.id/m/{bisnis} (ADR 0013): guard "member", bisnis dari kode URL.
 */
final class MemberPortal
{
    public const GUARD = 'member';

    public const SESSION_PASSWORD = 'member.password_hash';

    private const TENANT_BINDING = 'member-portal.tenant';

    public static function guard(): StatefulGuard
    {
        return Auth::guard(self::GUARD);
    }

    public static function member(): ?Member
    {
        $member = self::guard()->user();

        return $member instanceof Member ? $member : null;
    }

    public static function setTenant(Tenant $tenant): void
    {
        app()->instance(self::TENANT_BINDING, $tenant);
    }

    public static function tenant(): Tenant
    {
        $tenant = app()->bound(self::TENANT_BINDING) ? app(self::TENANT_BINDING) : null;

        return $tenant instanceof Tenant ? $tenant : abort(404);
    }

    /** Sidik kata sandi di sesi: ganti kata sandi = sesi lain keluar. */
    public static function passwordFingerprint(Member $member): string
    {
        return hash_hmac('sha256', (string) $member->getAttribute('password'), (string) config('app.key'));
    }

    public static function login(Request $request, Member $member): void
    {
        self::guard()->login($member);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_PASSWORD, self::passwordFingerprint($member));
    }

    public static function logout(Request $request): void
    {
        self::guard()->logout();
        $request->session()->forget(self::SESSION_PASSWORD);
        $request->session()->regenerateToken();
    }
}
