<?php

declare(strict_types=1);

namespace App\Http\Middleware\SelfOrder;

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\SelfOrderSetting;
use App\Models\Tenant;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman pesan dari meja gspos.id/order/t/{token} (ADR 0014): meja dari token QR (aktif), outlet aktif, bisnis
 * aktif; selain itu 404 (QR tidak valid / sudah diganti). Tenant context diisi sebelum data dibaca. Status
 * self-order (nonaktif, di luar jam, bisnis hanya-baca) ditampilkan halaman, bukan 404.
 */
final class SetSelfOrderTable
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');
        // Satu-satunya query lintas tenant: token acak 32 karakter, unik global
        $table = strlen($token) === 32 ? DiningTable::allTenants()->withoutGlobalScopes()->where('qr_token', $token)->where('is_active', true)->first() : null;
        $tenant = $table !== null ? Tenant::query()->find($table->tenant_id) : null;

        abort_if($table === null || $tenant === null || ! $tenant->isActive(), 404);

        TenantContext::set($tenant->id, readOnly: $tenant->isReadOnly());
        $outlet = Outlet::query()->where('is_active', true)->find($table->outlet_id);
        abort_if($outlet === null, 404);

        SelfOrderContext::set(new SelfOrderContext($tenant, $outlet, $table, SelfOrderSetting::forOutlet($outlet->id)));

        return $next($request);
    }
}
