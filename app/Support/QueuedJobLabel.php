<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nama job antrean yang bisa dibaca admin (Dasbor & Queue). Hanya dari nama kelas di payload; isi data job (bisa memuat
 * data pribadi, mis. email penerima) tidak pernah ditampilkan.
 */
final class QueuedJobLabel
{
    private const LABELS = [
        'App\\Jobs\\RunBackup' => 'Backup database & file',
        'App\\Notifications\\DailySalesSummary' => 'Email ringkasan penjualan harian',
        'App\\Notifications\\InviteOwner' => 'Email undangan owner',
        'App\\Notifications\\InvoiceCreated' => 'Email tagihan langganan baru',
        'App\\Notifications\\InvoicePaid' => 'Email kuitansi tagihan lunas',
        'App\\Notifications\\PaymentProofSubmitted' => 'Email bukti transfer ke admin',
        'App\\Notifications\\PaymentProofRejected' => 'Email bukti transfer ditolak',
        'App\\Notifications\\ResetUserPassword' => 'Email reset kata sandi',
        'App\\Notifications\\SubscriptionEndingSoon' => 'Email pengingat langganan berakhir',
        'App\\Notifications\\VerifyEmployeeEmail' => 'Email verifikasi karyawan',
        'App\\Notifications\\VerifyOwnerEmail' => 'Email verifikasi owner',
    ];

    /** @return array{label: string, class: string} */
    public static function fromPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $class = is_array($decoded) && is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : '';

        // Notifikasi antrean: kelas notifikasi ada di command yang di-serialize (ambil nama kelasnya saja)
        if ($class === 'Illuminate\\Notifications\\SendQueuedNotifications' || str_starts_with($class, 'App\\Notifications\\')) {
            // Kelas sudah terbaca, jadi payload pasti array
            $command = (string) ($decoded['data']['command'] ?? '');
            if (preg_match('/O:\d+:"(App\\\\Notifications\\\\[A-Za-z0-9_]+)"/', $command, $m) === 1) {
                $class = $m[1];
            }
        }

        return [
            'label' => self::LABELS[$class] ?? ($class !== '' ? class_basename($class) : 'Tidak diketahui'),
            'class' => $class,
        ];
    }
}
