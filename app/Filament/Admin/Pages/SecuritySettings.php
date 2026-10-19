<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\SetAdminTwoFactorRequirement;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use App\Services\Admin\SecurityChecklist;
use App\Support\SystemSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Sistem → Keamanan: on/off kewajiban 2FA untuk semua super admin, status 2FA tiap admin, pemeriksaan konfigurasi
 * (SecurityChecklist), dan ringkasan perlindungan bawaan. Setiap admin mengatur 2FA miliknya dari halaman profil.
 */
final class SecuritySettings extends Page
{
    use HasIconBreadcrumbs;

    protected string $view = 'filament.admin.pages.security-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Keamanan';

    protected static ?string $title = 'Keamanan';

    protected static ?string $slug = 'security';

    public function isTwoFactorRequired(): bool
    {
        return app(SystemSettings::class)->adminTwoFactorRequired();
    }

    public function currentAdminHasTwoFactor(): bool
    {
        return $this->admin()->hasTwoFactorEnabled();
    }

    public function getSubheading(): string
    {
        return 'Kebijakan login super admin dan pemeriksaan konfigurasi server';
    }

    /** @return list<array{key: string, label: string, ok: bool, value: string, fix: string}> */
    public function checklist(): array
    {
        return app(SecurityChecklist::class)->items();
    }

    /** @return Collection<int, Admin> */
    public function admins(): Collection
    {
        return Admin::query()->orderBy('name')->get(['id', 'name', 'email', 'last_login_at', 'app_authentication_secret']);
    }

    /** Batas percobaan login per menit (pos.rate_limits.auth) untuk kartu perlindungan. */
    public function loginRateLimit(): int
    {
        return (int) config('pos.rate_limits.auth');
    }

    public function adminsUrl(): string
    {
        return AdminResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->toggleTwoFactorAction(),
        ];
    }

    private function toggleTwoFactorAction(): Action
    {
        $required = $this->isTwoFactorRequired();

        return Action::make('toggleTwoFactor')
            ->label($required ? 'Nonaktifkan wajib 2FA' : 'Wajibkan 2FA')
            ->icon($required ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedLockClosed)
            ->color($required ? 'danger' : 'primary')
            ->requiresConfirmation()
            ->modalIcon($required ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedShieldCheck)
            ->modalHeading($required ? 'Nonaktifkan wajib 2FA?' : 'Wajibkan 2FA untuk semua super admin?')
            ->modalDescription($required
                ? 'Super admin bisa login hanya dengan kata sandi. Akun yang sudah memakai 2FA tetap diminta kode'
                : 'Super admin yang belum memakai 2FA akan diminta mengaturnya saat membuka panel berikutnya')
            ->modalSubmitActionLabel($required ? 'Nonaktifkan' : 'Wajibkan 2FA')
            // Konfirmasi kata sandi: sesi yang dibajak tidak bisa mengubah kebijakan keamanan
            ->schema([
                TextInput::make('current_password')
                    ->label('Kata sandi Anda')
                    ->password()
                    ->required()
                    ->currentPassword(guard: 'admin'),
            ])
            ->action(function () use ($required): void {
                app(SetAdminTwoFactorRequirement::class)->handle($this->admin(), ! $required);

                Notification::make()
                    ->success()
                    ->title($required ? 'Wajib 2FA dinonaktifkan' : 'Wajib 2FA diaktifkan')
                    ->send();

                // Muat ulang agar label aksi & middleware 2FA memakai nilai terbaru
                $this->redirect(self::getUrl());
            });
    }

    private function admin(): Admin
    {
        $admin = auth('admin')->user();

        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
