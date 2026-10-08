<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\TenantStatus;
use App\Filament\Shared\Layout;
use App\Models\Admin;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Aksi ubah status tenant, dipakai di tabel & halaman detail (row-actions.md, modal.md).
 */
final class TenantStatusActions
{
    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Tangguhkan')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('danger')
            ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Suspended)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading('Tangguhkan tenant')
            ->modalDescription(fn (Tenant $record): HtmlString => Layout::confirmText(
                'Yakin ingin menangguhkan <strong>'.e($record->name).'</strong>?',
                'Semua karyawan tenant ini langsung tidak bisa memakai aplikasi kasir dan dashboard sampai diaktifkan kembali.',
            ))
            ->modalSubmitActionLabel('Tangguhkan tenant')
            ->action(function (Tenant $record): void {
                app(ChangeTenantStatus::class)->handle($record, TenantStatus::Suspended, self::admin());

                Notification::make()->success()->title('Tenant berhasil ditangguhkan')->send();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->label('Aktifkan')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Active)
            ->requiresConfirmation()
            ->modalHeading('Aktifkan tenant')
            ->modalDescription(fn (Tenant $record): HtmlString => Layout::confirmText(
                'Yakin ingin mengaktifkan <strong>'.e($record->name).'</strong>?',
                'Pastikan masa langganan sudah diperpanjang bila sebelumnya berakhir.',
            ))
            ->modalSubmitActionLabel('Aktifkan tenant')
            ->action(function (Tenant $record): void {
                app(ChangeTenantStatus::class)->handle($record, TenantStatus::Active, self::admin());

                Notification::make()->success()->title('Tenant berhasil diaktifkan')->send();
            });
    }

    private static function admin(): ?Admin
    {
        $user = auth('admin')->user();

        return $user instanceof Admin ? $user : null;
    }
}
