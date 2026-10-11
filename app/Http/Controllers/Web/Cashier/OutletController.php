<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cashier;

use App\Actions\Cashier\RegisterWebDevice;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\User;
use App\Support\WebCashier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Pilih outlet kasir web (Q54): browser didaftarkan sebagai device web di outlet itu.
 * Satu outlet aktif → langsung dipilih.
 */
final class OutletController extends Controller
{
    public function index(Request $request, RegisterWebDevice $register): View|RedirectResponse
    {
        $outlets = $this->outlets(self::user());

        if ($outlets->count() === 1 && ! $request->session()->has('status') && ! $request->session()->has('errors')) {
            return $this->select($request, $register, $outlets->first());
        }

        return view('cashier.outlet', ['outlets' => $outlets, 'user' => self::user()]);
    }

    public function store(Request $request, RegisterWebDevice $register): RedirectResponse
    {
        $outlet = $this->outlets(self::user())->firstWhere('id', $request->string('outlet_id')->toString());
        abort_if($outlet === null, 404);

        return $this->select($request, $register, $outlet);
    }

    private function select(Request $request, RegisterWebDevice $register, Outlet $outlet): RedirectResponse
    {
        $browserId = $request->cookie(WebCashier::BROWSER_COOKIE);

        if (! is_string($browserId) || ! Str::isUuid($browserId)) {
            $browserId = (string) Str::uuid();
            // 5 tahun: browser yang sama tetap device yang sama (shift tidak tertinggal)
            Cookie::queue(WebCashier::BROWSER_COOKIE, $browserId, 60 * 24 * 365 * 5);
        }

        try {
            $device = $register->handle(self::user(), $outlet, $browserId);
        } catch (BusinessException $e) {
            return redirect()->route('cashier.outlet')->withErrors(['outlet_id' => $e->getMessage()]);
        }

        $request->session()->put(WebCashier::SESSION_DEVICE, $device->id);

        return redirect()->route('cashier.home');
    }

    /** @return Collection<int, Outlet> */
    private function outlets(User $user): Collection
    {
        return $user->accessibleOutlets()->where('is_active', true)->get();
    }

    private static function user(): User
    {
        return WebCashier::user() ?? abort(401);
    }
}
