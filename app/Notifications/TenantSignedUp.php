<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pendaftar baru di gspos.id/register → email tim (Sistem → Pendaftaran → Email notifikasi). Lewat antrean database;
 * data disalin sebagai teks agar antrean tidak memuat ulang model ber-tenant.
 */
final class TenantSignedUp extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $tenantId;

    public readonly string $businessName;

    public readonly string $businessType;

    public readonly string $ownerName;

    public readonly string $ownerEmail;

    public readonly string $trialEnds;

    public function __construct(Tenant $tenant, User $owner)
    {
        $this->tenantId = $tenant->id;
        $this->businessName = $tenant->name;
        $this->businessType = $tenant->business_type->getLabel();
        $this->ownerName = $owner->name;
        $this->ownerEmail = (string) $owner->email;
        $this->trialEnds = $tenant->subscription_ends_at?->setTimezone((string) config('pos.default_timezone'))->locale('id')->translatedFormat('j F Y') ?? '—';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Pendaftar baru: {$this->businessName}")
            ->greeting('Halo tim gs.POS,')
            ->line("{$this->businessName} ({$this->businessType}) baru mendaftar sendiri.")
            ->line("Owner: {$this->ownerName} · {$this->ownerEmail}")
            ->line("Trial sampai {$this->trialEnds}.")
            ->action('Lihat bisnis', TenantResource::getUrl('view', ['record' => $this->tenantId], panel: 'admin'));
    }
}
