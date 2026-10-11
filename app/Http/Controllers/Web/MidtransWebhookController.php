<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Payment\HandleMidtransNotification;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/midtrans/{tenant} (ADR 0014, Q72): URL notifikasi yang owner pasang di dashboard Midtrans miliknya.
 * Tanpa sesi/CSRF; keaslian dari tanda tangan + cek ulang status ke API (HandleMidtransNotification).
 */
final class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, string $tenant, HandleMidtransNotification $handle): JsonResponse
    {
        $model = Tenant::query()->find($tenant);

        if ($model === null) {
            return response()->json(['ok' => false], 404);
        }

        try {
            $status = TenantContext::run($model->id, fn (): int => $handle->handle($request->json()->all()));
        } catch (BusinessException) {
            // Midtrans tidak bisa dihubungi untuk cek ulang: minta Midtrans mengirim ulang nanti
            $status = 503;
        }

        return response()->json(['ok' => $status === 200], $status);
    }
}
