<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Cetak ulang struk dari dashboard (T3, permission order.reprint — sama dengan GET /orders/{id}/receipt API).
 * Data dari ReceiptResource agar isi struk web & aplikasi kasir sama. Desain struk sementara (80mm sederhana).
 */
final class ReceiptController extends Controller
{
    public function __invoke(Request $request, string $order): Response
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        // Query tenant-scoped (fail-closed): order tenant/outlet lain → 404
        $model = Order::query()->with(['items.options', 'payments.method', 'user', 'outlet'])->findOrFail($order);
        Gate::forUser($user)->authorize('reprint', $model);

        // Data transaksi: jangan disimpan cache browser/proxy
        return response()->view('receipts.print', [
            'receipt' => (new ReceiptResource($model))->resolve($request),
            'autoprint' => $request->boolean('print'),
        ])->header('Cache-Control', 'no-store, private');
    }
}
