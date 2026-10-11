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
 * Pemilih outlet di sidebar dashboard (ADR 0010, Q41). "all" = semua outlet yang dipegang user.
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
        $home = (string) Filament::getUrl();

        return redirect()->to(self::isWithinPanel($back, $home) ? $back : $home);
    }

    /**
     * URL berada di panel ini: skema, host, dan port sama persis, path di bawah path panel. Bukan
     * perbandingan awalan string — "https://app.gspos.id.evil.tld" lolos cara itu (GSPOS-SEC-023).
     */
    private static function isWithinPanel(string $url, string $home): bool
    {
        $target = parse_url($url);
        $panel = parse_url($home);

        if (! is_array($target) || ! is_array($panel) || ! isset($target['host'], $panel['host'])) {
            return false;
        }

        foreach (['scheme', 'host', 'port'] as $part) {
            if (strtolower((string) ($target[$part] ?? '')) !== strtolower((string) ($panel[$part] ?? ''))) {
                return false;
            }
        }

        $base = rtrim($panel['path'] ?? '', '/');
        $path = $target['path'] ?? '/';

        return $base === '' || $path === $base || str_starts_with($path, $base.'/');
    }
}
