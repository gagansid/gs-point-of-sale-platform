<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Member;

use App\Actions\Member\AnonymizeMember;
use App\Actions\Member\ChangeMemberPassword;
use App\Actions\Member\Data\MemberData;
use App\Actions\Member\SaveMember;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Models\MemberTier;
use App\Models\Order;
use App\Services\Member\MemberProof;
use App\Services\Member\StampCards;
use App\Support\MemberPortal;
use App\Support\Money;
use App\Support\QrSvg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Halaman member setelah masuk (ADR 0013, Q67): QR dinamis, saldo & riwayat, profil, kata sandi, hapus akun.
 */
final class PortalController extends Controller
{
    public function home(StampCards $stamps): View
    {
        $member = self::member();

        $tiers = MemberTier::ordered();
        $tier = $tiers->firstWhere('id', $member->tier_id);

        return view('member.home', [
            'member' => $member,
            'settings' => MemberSetting::current(),
            // Tier & progres ke tier berikutnya (M5)
            'tier' => $tier,
            'nextTier' => MemberTier::nextAfter($tier, Money::fromDb($member->spend_12m), $tiers),
            // Kartu stempel aktif (M6)
            'stampCards' => $stamps->summary($member),
            'proof' => self::proof($member),
            'expiring' => MemberPointEntry::query()->where('member_id', $member->id)->where('remaining', '>', 0)
                ->whereNotNull('expires_at')->orderBy('expires_at')->first(),
        ]);
    }

    /** Kode QR terbaru (dipanggil halaman tiap pergantian jendela 30 detik). */
    public function code(): JsonResponse
    {
        return response()->json(self::proof(self::member()))->header('Cache-Control', 'no-store');
    }

    public function history(): View
    {
        $member = self::member();

        return view('member.history', [
            'member' => $member,
            'entries' => MemberPointEntry::query()->where('member_id', $member->id)->latest()->limit(50)->get(),
            'orders' => Order::query()->where('member_id', $member->id)->with('outlet:id,name')->latest()->limit(20)->get(),
        ]);
    }

    public function profile(): View
    {
        return view('member.profile', ['member' => self::member()]);
    }

    public function updateProfile(Request $request, SaveMember $save): RedirectResponse
    {
        $member = self::member();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'required_with:birth_day', 'integer', 'between:1,12'],
        ], [], ['name' => 'nama', 'birth_day' => 'tanggal lahir', 'birth_month' => 'bulan lahir']);

        try {
            // Nomor HP tidak bisa diganti sendiri (identitas login & kasir)
            $save->handle($member, MemberData::fromArray([...$data, 'phone' => (string) $member->phone, 'notes' => $member->notes]));
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(is_array($e->details) ? $e->details : ['name' => $e->getMessage()]);
        }

        return back()->with('status', 'Profil disimpan');
    }

    public function editPassword(): View
    {
        return view('member.password', ['member' => self::member()]);
    }

    public function updatePassword(Request $request, ChangeMemberPassword $change): RedirectResponse
    {
        $member = self::member();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru']);

        if (! Hash::check($data['current_password'], (string) $member->getAttribute('password'))) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah']);
        }

        $change->handle($member, $data['password']);
        // Sesi ini tetap; sesi lain keluar (sidik kata sandi berubah)
        $request->session()->put(MemberPortal::SESSION_PASSWORD, MemberPortal::passwordFingerprint($member->refresh()));

        return redirect()->route('member.home', ['business' => MemberPortal::tenant()->slug])->with('status', 'Kata sandi diganti');
    }

    public function destroy(Request $request, AnonymizeMember $anonymize): RedirectResponse
    {
        $member = self::member();
        $request->validate(['password' => ['required', 'string']], [], ['password' => 'kata sandi']);

        if (! Hash::check((string) $request->input('password'), (string) $member->getAttribute('password'))) {
            throw ValidationException::withMessages(['password' => 'Kata sandi salah']);
        }

        $anonymize->handle($member);
        MemberPortal::logout($request);

        return redirect()->route('member.login', ['business' => MemberPortal::tenant()->slug])->with('status', 'Akun member Anda sudah dihapus.');
    }

    private static function member(): Member
    {
        return MemberPortal::member() ?? abort(401);
    }

    /** @return array{payload: string, code: string, seconds_left: int, svg: string} */
    private static function proof(Member $member): array
    {
        $code = MemberProof::current($member);
        // Satu kali scan di kasir: kode member (memasang member) + kode bukti (menukar poin)
        $payload = $member->code.'-'.$code;

        return ['payload' => $payload, 'code' => $code, 'seconds_left' => MemberProof::secondsLeft(), 'svg' => QrSvg::make($payload)];
    }
}
