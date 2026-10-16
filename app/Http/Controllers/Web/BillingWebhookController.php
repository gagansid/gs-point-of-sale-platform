<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Billing\HandleBillingNotification;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/billing/midtrans (ADR 0024): notifikasi Midtrans milik vendor untuk tagihan langganan. Tanpa sesi/CSRF;
 * keaslian dari tanda tangan + cek ulang status ke API (HandleBillingNotification).
 */
final class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, HandleBillingNotification $handle): JsonResponse
    {
        try {
            $status = $handle->handle($request->json()->all());
        } catch (BusinessException) {
            // Midtrans tidak bisa dihubungi untuk cek ulang: minta Midtrans mengirim ulang nanti
            $status = 503;
        }

        return response()->json(['ok' => $status === 200], $status);
    }
}
