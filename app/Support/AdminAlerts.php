<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Admin\ManageBackups;
use App\Enums\InvoiceStatus;
use App\Enums\SalesLeadStatus;
use App\Filament\Admin\Pages\BackupManager;
use App\Filament\Admin\Pages\MaintenanceMode;
use App\Filament\Admin\Pages\QueueMonitor;
use App\Filament\Admin\Resources\SalesLeads\SalesLeadResource;
use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Models\FailedJob;
use App\Models\SalesLead;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Peringatan di topbar panel /admin (lonceng, Q135): hal yang perlu ditindak super admin, dihitung langsung dari data
 * (tanpa job). Pola sama dengan DashboardAlerts; hasilnya disimpan sebagai notifikasi per admin oleh AlertInbox (Q139);
 * tautan membuka tab yang tepat lewat `?tab=` (HasCardTabs).
 */
final class AdminAlerts
{
    /** Backup terakhir dianggap usang setelah sekian jam (sama dengan monitor backup: maks. 1 hari). */
    public const BACKUP_MAX_AGE_HOURS = 24;

    /**
     * @return list<array{key: string, icon: string, color: string, title: string, description: string, url: string, count: int}>
     */
    public static function all(): array
    {
        $alerts = [];

        // Layanan sedang dimatikan: paling penting, tampil pertama
        if (app()->isDownForMaintenance()) {
            $alerts[] = self::alert('maintenance', 'heroicon-o-wrench-screwdriver', 'danger',
                'Mode pemeliharaan aktif', 'Dashboard, kasir, dan API tertutup untuk pelanggan', MaintenanceMode::getUrl());
        }

        if (Edition::isSaas()) {
            $proofs = SubscriptionInvoice::query()->withoutGlobalScopes()
                ->where('status', InvoiceStatus::Pending)->whereNotNull('proof_submitted_at')->count();
            if ($proofs > 0) {
                $alerts[] = self::alert('proofs', 'heroicon-o-document-check', 'warning',
                    self::count($proofs).' bukti transfer menunggu verifikasi', 'Tandai lunas atau tolak bukti',
                    SubscriptionInvoiceResource::getUrl('index', ['tab' => 'verify']), $proofs);
            }

            $overdue = SubscriptionInvoice::query()->withoutGlobalScopes()
                ->where('status', InvoiceStatus::Pending)->where('due_at', '<', now())->whereNull('proof_submitted_at')->count();
            if ($overdue > 0) {
                $alerts[] = self::alert('overdue', 'heroicon-o-exclamation-triangle', 'danger',
                    self::count($overdue).' tagihan lewat jatuh tempo', 'Belum dibayar dan belum ada bukti transfer',
                    SubscriptionInvoiceResource::getUrl('index', ['tab' => 'overdue']), $overdue);
            }

            if (SalesLeadResource::canAccess()) {
                $leads = SalesLead::query()->where('status', SalesLeadStatus::New)->count();
                if ($leads > 0) {
                    $alerts[] = self::alert('leads', 'heroicon-o-chat-bubble-left-right', 'info',
                        self::count($leads).' calon pelanggan baru', 'Belum dihubungi',
                        SalesLeadResource::getUrl('index', ['tab' => SalesLeadStatus::New->value]), $leads);
                }
            }
        }

        $failed = FailedJob::query()->count();
        if ($failed > 0) {
            $alerts[] = self::alert('failed_jobs', 'heroicon-o-queue-list', 'danger',
                self::count($failed).' job antrean gagal', 'Email, backup, atau proses latar belakang tidak berjalan', QueueMonitor::getUrl(), $failed);
        }

        $backupAt = self::latestBackupAt();
        if ($backupAt === null || $backupAt->lt(CarbonImmutable::now()->subHours(self::BACKUP_MAX_AGE_HOURS))) {
            $alerts[] = self::alert('backup', 'heroicon-o-circle-stack', 'warning',
                $backupAt === null ? 'Belum ada backup' : 'Backup terakhir lebih dari 1 hari',
                $backupAt === null ? 'Jalankan backup pertama dan pastikan cron berjalan' : 'Terakhir '.$backupAt->locale('id')->diffForHumans(),
                BackupManager::getUrl());
        }

        return $alerts;
    }

    /** Waktu backup terbaru di disk backup; null = belum ada atau disk tidak terbaca. */
    private static function latestBackupAt(): ?CarbonImmutable
    {
        try {
            $latest = app(ManageBackups::class)->list()[0]['created'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $latest !== null ? CarbonImmutable::createFromTimestamp($latest) : null;
    }

    /**
     * @param  int  $count  jumlah hal yang perlu ditindak; naik = notifikasi baru belum dibaca (AlertInbox)
     * @return array{key: string, icon: string, color: string, title: string, description: string, url: string, count: int}
     */
    private static function alert(string $key, string $icon, string $color, string $title, string $description, string $url, int $count = 1): array
    {
        return compact('key', 'icon', 'color', 'title', 'description', 'url', 'count');
    }

    private static function count(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
