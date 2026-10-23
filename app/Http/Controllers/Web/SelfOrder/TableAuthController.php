<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SelfOrder;

use App\Actions\Member\AuthenticateMember;
use App\Actions\Member\SignUpMember;
use App\Http\Controllers\Controller;
use App\Livewire\SelfOrder\OrderPage;
use App\Support\MemberPortal;
use App\Support\SelfOrderContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Masuk / daftar singkat member dari halaman pesan meja (ADR 0027 K1, K5): bertema bisnis, sesi member yang sama dengan
 * gspos.id/m/{bisnis}. Keranjang tetap di session per meja; setelah berhasil kembali ke meja (ke Keranjang bila masuk
 * dimulai dari sana). Program member nonaktif = halaman tidak ada (404).
 */
final class TableAuthController extends Controller
{
    public function loginForm(Request $request): View|RedirectResponse
    {
        $context = self::context();
        self::rememberIntent($request, $context);

        return $context->member() !== null ? redirect()->to($context->url()) : view('self-order.login', ['context' => $context]);
    }

    public function login(Request $request, AuthenticateMember $authenticate): RedirectResponse
    {
        $context = self::context();
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:255']], [], ['phone' => 'nomor HP', 'password' => 'kata sandi']);
        $member = $authenticate->handle($context->tenant, $data['phone'], $data['password'], (string) $request->ip());
        MemberPortal::login($request, $member);

        // Kata sandi sementara dari kasir: ganti dulu di halaman member, lalu kembali ke meja
        if ($member->getAttribute('must_change_password')) {
            $request->session()->put('member.return_to', $context->url());

            return redirect()->route('member.password.edit', ['business' => $context->tenant->slug]);
        }

        return redirect()->to($context->url());
    }

    public function registerForm(Request $request): View|RedirectResponse
    {
        $context = self::context();
        self::rememberIntent($request, $context);

        return $context->member() !== null ? redirect()->to($context->url()) : view('self-order.register', ['context' => $context]);
    }

    public function register(Request $request, SignUpMember $signUp): RedirectResponse
    {
        $context = self::context();
        $member = $signUp->handle($request->only(['name', 'phone', 'password', 'consent']), (string) $request->ip(), confirmPassword: false);
        MemberPortal::login($request, $member);

        return redirect()->to($context->url());
    }

    public function logout(Request $request): RedirectResponse
    {
        $context = self::context();
        MemberPortal::logout($request);

        return redirect()->to($context->url());
    }

    /** Konteks meja + program member wajib aktif. */
    private static function context(): SelfOrderContext
    {
        $context = SelfOrderContext::get();
        abort_unless($context->loginRequired(), 404);

        return $context;
    }

    /** Masuk dari Keranjang (?from=cart) → setelah berhasil halaman meja membuka Keranjang lagi. */
    private static function rememberIntent(Request $request, SelfOrderContext $context): void
    {
        if ($request->query('from') === 'cart') {
            $request->session()->put(OrderPage::resumeKey($context->table->id), 'cart');
        }
    }
}
