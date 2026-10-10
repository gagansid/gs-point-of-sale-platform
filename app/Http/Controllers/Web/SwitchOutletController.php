<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CurrentOutlet;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pemilih outlet di topbar dashboard (ADR 0010, Q41). "all" = semua outlet yang dipegang user.
 * Outlet di luar penugasan user → 404 (CurrentOutlet::choose).
 */
final class SwitchOutletController extends Controller
{
    public function __invoke(Request $request, string $outlet): RedirectResponse
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        CurrentOutlet::choose($user, $outlet === 'all' ? null : $outlet);

        // Kembali ke halaman asal (hanya URL panel ini; selain itu ke beranda)
        $back = url()->previous();
        $home = Filament::getUrl();

        return redirect()->to(str_starts_with($back, rtrim((string) $home, '/')) ? $back : $home);
    }
}
