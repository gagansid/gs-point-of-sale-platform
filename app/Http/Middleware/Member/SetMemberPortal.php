<?php

declare(strict_types=1);

namespace App\Http\Middleware\Member;

use App\Models\MemberSetting;
use App\Models\Tenant;
use App\Support\Features;
use App\Support\MemberPortal;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman member gspos.id/m/{bisnis} (ADR 0013): bisnis dari kode URL (aktif & program member aktif, selain itu
 * 404), tenant context diisi SEBELUM guard membaca member (tenant scope). Parameter "auth" = wajib masuk; sesi
 * member diputus bila kata sandi diganti, member nonaktif/dihapus. Kata sandi sementara → wajib diganti dulu.
 */
final class SetMemberPortal
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $slug = (string) $request->route('business');
        $tenant = Tenant::query()->where('slug', mb_strtolower($slug))->first();

        abort_if($tenant === null || ! $tenant->isActive(), 404);

        TenantContext::set($tenant->id, readOnly: $tenant->isReadOnly());
        // Program member nonaktif atau tidak termasuk paket (ADR 0025): halaman tidak ada
        abort_unless(MemberSetting::current()->enabled && Features::enabled('member', $tenant), 404);
        MemberPortal::setTenant($tenant);
        view()->share('business', $tenant);

        $member = MemberPortal::member();

        if ($member !== null) {
            // Dibaca ulang: kata sandi/status terbaru; sesi bisnis lain tidak berlaku di sini
            $member = $member->fresh();
            $valid = $member !== null && $member->tenant_id === $tenant->id && $member->isUsable()
                && hash_equals((string) $request->session()->get(MemberPortal::SESSION_PASSWORD), MemberPortal::passwordFingerprint($member));

            if (! $valid) {
                MemberPortal::logout($request);
                $member = null;
            }
        }

        if ($mode === 'auth') {
            if ($member === null) {
                return redirect()->route('member.login', ['business' => $tenant->slug]);
            }

            if ($member->getAttribute('must_change_password') && ! $request->routeIs('member.password.*', 'member.logout')) {
                return redirect()->route('member.password.edit', ['business' => $tenant->slug]);
            }
        }

        return $next($request);
    }
}
