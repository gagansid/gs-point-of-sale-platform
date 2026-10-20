<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SystemSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF tagihan langganan (Q126): sebelum lunas = dokumen *Tagihan* (rincian, jatuh tempo, cara bayar); setelah lunas =
 * *Kuitansi* (cap LUNAS, terbilang, metode & waktu bayar). Satu template (billing.pdf.invoice), A4. Dipakai unduhan
 * owner & admin serta lampiran email lunas. Pemanggil wajib sudah memastikan akses ke tagihan.
 */
final class InvoicePdf
{
    public function render(SubscriptionInvoice $invoice): string
    {
        $invoice->loadMissing('items');
        $tenant = Tenant::query()->find($invoice->tenant_id);
        $owner = User::allTenants()->where('tenant_id', $invoice->tenant_id)->where('role', UserRole::Owner)
            ->where('is_active', true)->orderBy('created_at')->first(['name', 'email']);

        $pdf = Pdf::loadView('billing.pdf.invoice', [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'owner' => $owner,
            'receipt' => self::isReceipt($invoice),
            'issuer' => (array) config('pos.billing.issuer'),
            'timezone' => (string) config('pos.default_timezone'),
            'manual' => app(SystemSettings::class)->billingBankAccounts(),
        ])->setPaper('a4');

        return (string) $pdf->output();
    }

    public function download(SubscriptionInvoice $invoice): StreamedResponse
    {
        $content = $this->render($invoice);

        return response()->streamDownload(fn () => print ($content), self::filename($invoice), [
            'Content-Type' => 'application/pdf',
            // Dokumen tagihan berisi data bisnis: jangan disimpan cache perantara
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function isReceipt(SubscriptionInvoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Paid;
    }

    /** "kuitansi-INV-2610-ABC123.pdf" / "tagihan-INV-2610-ABC123.pdf" */
    public static function filename(SubscriptionInvoice $invoice): string
    {
        return (self::isReceipt($invoice) ? 'kuitansi-' : 'tagihan-').preg_replace('/[^A-Za-z0-9-]/', '', $invoice->number).'.pdf';
    }
}
