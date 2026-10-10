<?php

declare(strict_types=1);

namespace App\Http\Middleware\Filament;

use App\Enums\ErrorCode;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel /dashboard: mengisi TenantContext dari user login (ADR 0007).
 *
 * Didaftarkan sebagai auth middleware PERSISTENT agar juga berjalan di request Livewire
 * (aksi tabel, simpan form); tanpa itu TenantScope fail-closed membuat semua data kosong.
 * Tenant ditangguhkan / user nonaktif → logout dengan pesan.
 */
final class SetDashboardTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active || ! $user->tenant->isActive()) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Notification::make()
                ->danger()
                ->title($user->is_active ? ErrorCode::TenantSuspended->message() : 'Akun Anda dinonaktifkan')
                ->persistent()
                ->send();

            return redirect()->to(Filament::getLoginUrl());
        }

        // Trial/langganan habis: panel hanya-baca (ADR 0009); simpan/hapus data ditolak di BelongsToTenant
        TenantContext::set($user->tenant_id, readOnly: $user->tenant->isReadOnly());
        // Semua tanggal di panel tampil dalam zona outlet (ADR 0001)
        FilamentTimezone::set(CurrentOutlet::timezone());

        return $next($request);
    }
}
