<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\ShowsPasswordConfirmation;
use App\Models\Admin;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;

/**
 * Profil super admin, seragam dengan profil dashboard: kartu identitas & info akun di kiri (keluar dari perangkat lain),
 * kartu 2FA lalu form di kanan, tombol Batal · Simpan rata kanan. Kata sandi baru mengikuti aturan akun admin (min. 12, huruf besar & kecil, simbol); mengganti
 * kata sandi atau email wajib kata sandi saat ini (bawaan Filament). 2FA tetap di section aplikasi authenticator.
 */
class EditProfile extends BaseEditProfile
{
    use HasIconBreadcrumbs;
    use ShowsPasswordConfirmation;

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    /**
     * Dua kolom seperti profil dashboard: kiri kartu identitas & info akun (+ keluar dari perangkat lain), kanan kartu
     * 2FA lalu form (Data diri, Kata sandi, tombol Batal · Simpan).
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make()->extraAttributes(['class' => 'gs-profile-card'])->schema([
                    View::make('filament.admin.profile.identity')->viewData(fn (): array => self::identity($this->admin())),
                    Actions::make([$this->logoutOtherDevicesAction()])->fullWidth(),
                ]),
                Group::make([
                    ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                    $this->getFormContentComponent(),
                ])->columnSpan(['lg' => 2]),
            ]),
        ]);
    }

    /**
     * Keluar dari semua sesi admin lain (perangkat hilang / sesi lama). Wajib kata sandi saat ini; memakai
     * AuthenticateSession bawaan panel: sesi lain gugur pada request berikutnya, sesi ini tetap.
     */
    protected function logoutOtherDevicesAction(): Action
    {
        return Action::make('logoutOtherDevices')->label('Keluarkan sesi lain')->tooltip('Keluar dari semua perangkat lain')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')->outlined()
            ->modalHeading('Keluar dari semua perangkat lain?')
            ->modalDescription('Sesi panel admin di browser lain harus masuk ulang. Browser ini tetap masuk.')
            ->modalSubmitActionLabel('Keluarkan')
            ->schema([
                TextInput::make('password')->label('Kata sandi saat ini')->password()->revealable()->required()
                    ->currentPassword(guard: Filament::getAuthGuard()),
            ])
            ->action(function (array $data): void {
                $guard = Filament::auth();
                abort_unless($guard instanceof SessionGuard, 500);
                $guard->logoutOtherDevices((string) $data['password']);
                Notification::make()->success()->title('Perangkat lain dikeluarkan')->send();
            });
    }

    /**
     * Data kartu identitas admin (baca saja).
     *
     * @return array{initial: string, name: string, email: string, twoFactor: bool, rows: list<array{label: string, value: string}>}
     */
    private static function identity(Admin $admin): array
    {
        $admin = $admin->fresh() ?? $admin;
        $tz = 'Asia/Jakarta';

        return [
            'initial' => mb_strtoupper(mb_substr(trim($admin->name), 0, 1)),
            'name' => $admin->name,
            'email' => $admin->email,
            'twoFactor' => filled($admin->getAttribute('app_authentication_secret')),
            'rows' => [
                ['label' => 'Terakhir masuk', 'value' => $admin->last_login_at?->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') ?? '—'],
                ['label' => 'Dibuat', 'value' => $admin->created_at?->setTimezone($tz)->locale('id')->translatedFormat('j M Y') ?? '—'],
            ],
        ];
    }

    private function admin(): Admin
    {
        $admin = $this->getUser();

        return $admin instanceof Admin ? $admin : abort(403);
    }

    /** Kartu 2FA bergaya section standar (judul berikon + deskripsi), isi tetap komponen Filament. */
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        if (! Filament::hasMultiFactorAuthentication()) {
            return null;
        }

        $user = Filament::auth()->user();

        return Section::make('Verifikasi dua langkah (2FA)')->icon(Heroicon::OutlinedShieldCheck)
            ->description('Kode 6 digit dari aplikasi authenticator selain kata sandi')
            ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                ->sort(fn (MultiFactorAuthenticationProvider $p): int => $p->isEnabled($user) ? 0 : 1)
                ->map(fn (MultiFactorAuthenticationProvider $p): Component => Group::make($p->getManagementSchemaComponents())->statePath($p->getId()))
                ->all());
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Akun, kata sandi, dan verifikasi dua langkah';
    }

    public function defaultForm(Schema $schema): Schema
    {
        // Label di atas kolom (bukan inline bawaan Filament) seperti form lain
        return parent::defaultForm($schema)->inlineLabel(false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Data diri')->icon(Heroicon::OutlinedUserCircle)
                ->description('Nama tampil di menu akun & riwayat perubahan; email dipakai untuk masuk')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([$this->getNameFormComponent(), $this->getEmailFormComponent()]),
            Section::make('Kata sandi')->icon(Heroicon::OutlinedKey)
                ->description('Kosongkan bila tidak diganti. Mengganti kata sandi atau email wajib kata sandi saat ini')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        // Sama dengan CreateAdmin/ManageAdmin: super admin memegang akses lintas tenant
        return $component->rule(Password::defaults()->min(12)->mixedCase()->symbols())
            ->helperText('Min. 12 karakter, huruf besar & kecil, simbol');
    }

    protected function getBreadcrumbIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedUserCircle;
    }
}
