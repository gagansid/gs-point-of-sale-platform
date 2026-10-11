<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\User\UpdateOwnProfile;
use App\Models\User;
use App\Rules\SecurePin;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Profil owner/manager. Penyimpanan lewat Action (bukan $record->update bawaan Filament) agar
 * aturan email terverifikasi & pencabutan token berlaku (GSPOS-SEC-012, GSPOS-SEC-013).
 * PIN sendiri (approval & masuk kasir web, Q55) juga diatur di sini; mengganti PIN wajib kata sandi saat ini.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getPinFormComponent(),
            $this->getCurrentPasswordFormComponent(),
            $this->getDailySummaryFormComponent(),
        ]);
    }

    /** Ringkasan harian lewat email (Q57): hanya untuk yang boleh melihat laporan. */
    protected function getDailySummaryFormComponent(): Component
    {
        return Toggle::make('daily_summary_email')->label('Ringkasan harian')
            ->helperText('Email setiap pagi berisi omzet, void, selisih kas, dan target kemarin. Email harus sudah terverifikasi')
            ->visible(fn (): bool => $this->getUser() instanceof User && $this->getUser()->hasPermission('report.view'));
    }

    protected function getPinFormComponent(): Component
    {
        return TextInput::make('pin')->label('PIN')
            ->password()->revealable()
            ->rule(new SecurePin)->length(6)
            ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off'])
            ->dehydrated(fn (#[SensitiveParameter] ?string $state): bool => filled($state))
            ->live(debounce: 500)
            ->helperText(fn (): string => $this->getUser()->getAttributeValue('pin') !== null
                ? 'Kosongkan bila tidak diganti. 6 digit, bukan angka sama/berurutan'
                : 'Belum diatur. Untuk approval void/diskon & masuk kasir web. 6 digit, bukan angka sama/berurutan');
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getCurrentPasswordFormComponent();

        // Ganti PIN juga butuh kata sandi saat ini
        return $component->visible(fn (Get $get): bool => filled($get('password')) || filled($get('pin'))
            || ($get('email') !== $this->getUser()->getAttributeValue('email')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        /** @var User $record */
        return app(UpdateOwnProfile::class)->handle(
            $record,
            (string) $data['name'],
            (string) $data['email'],
            isset($data['password']) ? (string) $data['password'] : null,
            isset($data['pin']) ? (string) $data['pin'] : null,
            array_key_exists('daily_summary_email', $data) ? (bool) $data['daily_summary_email'] : null,
        );
    }
}
