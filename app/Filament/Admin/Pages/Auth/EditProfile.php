<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;

/**
 * Profil super admin, seragam dengan halaman form lain: breadcrumb berikon, section berikon, tombol Batal · Simpan
 * rata kanan, kartu 2FA di kolom kanan. Kata sandi baru mengikuti aturan akun admin (min. 12, huruf besar & kecil, simbol); mengganti
 * kata sandi atau email wajib kata sandi saat ini (bawaan Filament). 2FA tetap di section aplikasi authenticator.
 */
class EditProfile extends BaseEditProfile
{
    use HasIconBreadcrumbs;

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    /**
     * Dua kolom: form (Data diri, Kata sandi, tombol Batal · Simpan) di kiri, kartu 2FA di kanan. Tombol tidak
     * menempel (gs-form-page) karena halaman pendek dan kartu 2FA berada di luar form.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Group::make([$this->getFormContentComponent()])->columnSpan(['lg' => 2]),
                Group::make(Arr::wrap($this->getMultiFactorAuthenticationContentComponent())),
            ]),
        ]);
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
