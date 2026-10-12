<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\User\LogoutOtherSessions;
use App\Actions\User\UpdateOwnAvatar;
use App\Actions\User\UpdateOwnProfile;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Products\Schemas\ProductForm;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Rules\SecurePin;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Profil owner/manager: foto & data diri, keamanan (kata sandi & PIN), notifikasi, info akun, perangkat & sesi.
 * Penyimpanan lewat Action (bukan $record->update bawaan Filament) agar aturan email terverifikasi & pencabutan
 * token berlaku (GSPOS-SEC-012, GSPOS-SEC-013). Mengganti kata sandi, PIN, atau email wajib kata sandi saat ini.
 * Bagian punya anchor (#keamanan, #perangkat) untuk tautan dari menu akun.
 */
class EditProfile extends BaseEditProfile
{
    /** Bar tombol "Batal · Simpan" sticky rata kanan seperti form tambah/ubah lain (components.css) */
    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Batal');
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return ['gs-form-page'];
    }

    public function defaultForm(Schema $schema): Schema
    {
        // Label di atas kolom (bukan inline bawaan Filament) agar dua kolom tetap rata
        return parent::defaultForm($schema)->inlineLabel(false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                // Kiri: kartu profil (foto, nama, role) + info akun baca saja
                Section::make()
                    ->id('data-diri')
                    ->extraAttributes(['class' => 'gs-profile-card'])
                    ->schema([
                        $this->getAvatarFormComponent(),
                        View::make('filament.dashboard.profile.account-info')
                            ->viewData(fn (): array => self::accountInfo($this->profileUser())),
                    ]),

                // Kanan: form
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make('Data diri')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            $this->getNameFormComponent(),
                            $this->getEmailFormComponent(),
                            TextInput::make('phone')->label('Nomor HP')->tel()->maxLength(30)->placeholder('0812 3456 7890')
                                ->formatStateUsing(fn (?string $state): ?string => $state !== null ? Phone::display($state) : null),
                        ]),

                    Section::make('Keamanan')
                        ->description('Mengganti kata sandi, PIN, atau email wajib kata sandi saat ini. Kata sandi baru mengeluarkan aplikasi kasir yang memakai akun ini')
                        ->id('keamanan')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            $this->getPasswordFormComponent(),
                            $this->getPinFormComponent(),
                            $this->getPasswordConfirmationFormComponent(),
                            $this->getCurrentPasswordFormComponent(),
                        ]),

                    Section::make('Notifikasi')
                        ->visible(fn (): bool => $this->getUser() instanceof User && $this->getUser()->hasPermission('report.view'))
                        ->schema([$this->getDailySummaryFormComponent()]),

                    Section::make('Perangkat & sesi')
                        ->description('Tempat akun ini sedang masuk. Keluarkan semua bila ada perangkat yang tidak dikenal')
                        ->id('perangkat')
                        ->afterHeader([
                            Action::make('logoutOthers')->label('Keluar dari perangkat lain')->color('danger')->outlined()->size('sm')
                                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                                ->requiresConfirmation()
                                ->modalHeading('Keluar dari semua perangkat lain?')
                                ->modalDescription('Sesi dashboard lain dan aplikasi kasir/owner yang memakai akun ini harus masuk ulang. Browser ini tetap masuk.')
                                ->modalSubmitActionLabel('Keluarkan')
                                ->action(function (): void {
                                    $result = app(LogoutOtherSessions::class)->handle($this->profileUser(), session()->getId());
                                    Notification::make()->success()->title('Perangkat lain dikeluarkan')
                                        ->body($result['sessions'].' sesi dashboard & '.$result['tokens'].' perangkat aplikasi')->send();
                                }),
                        ])
                        ->schema([
                            View::make('filament.dashboard.profile.sessions')->viewData(fn (): array => self::sessions($this->profileUser())),
                        ]),
                ]),
            ]),
        ]);
    }

    /** Foto profil: gambar ≤ 2 MB, dipotong persegi, disimpan di folder tenant (UpdateOwnAvatar). */
    protected function getAvatarFormComponent(): Component
    {
        return FileUpload::make('avatar_path')->label('Foto profil')->hiddenLabel()
            // Persegi 1:1 (sama dengan hasil simpan); dibuat bulat hanya di avatar topbar
            ->image()->imageEditor()->imageEditorAspectRatios(['1:1'])->imageCropAspectRatio('1:1')
            ->panelAspectRatio('1:1')
            ->placeholder(ProductForm::uploadPlaceholder())
            ->disk('public')->directory(fn (): string => UpdateOwnAvatar::directory($this->profileUser()))->visibility('public')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)
            ->helperText('JPG, PNG, atau WEBP · maks. 2 MB · dipotong persegi')
            ->alignCenter()
            ->extraAttributes(['class' => 'gs-profile-avatar']);
    }

    /** Ringkasan harian lewat email (Q57): hanya untuk yang boleh melihat laporan. */
    protected function getDailySummaryFormComponent(): Component
    {
        return Toggle::make('daily_summary_email')->label('Kirim ringkasan harian lewat email')
            ->helperText('Email setiap pagi berisi omzet, void, selisih kas, dan target kemarin. Email harus sudah terverifikasi');
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
        try {
            return DB::transaction(function () use ($record, $data): User {
                $user = app(UpdateOwnProfile::class)->handle(
                    $record,
                    (string) $data['name'],
                    (string) $data['email'],
                    isset($data['password']) ? (string) $data['password'] : null,
                    isset($data['pin']) ? (string) $data['pin'] : null,
                    array_key_exists('daily_summary_email', $data) ? (bool) $data['daily_summary_email'] : null,
                    array_key_exists('phone', $data) ? (filled($data['phone']) ? (string) $data['phone'] : null) : false,
                );

                return array_key_exists('avatar_path', $data)
                    ? app(UpdateOwnAvatar::class)->handle($user, filled($data['avatar_path']) ? (string) $data['avatar_path'] : null)
                    : $user;
            });
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(collect(is_array($e->details) ? $e->details : [])
                ->mapWithKeys(fn (mixed $messages, string $field): array => ["data.{$field}" => (array) $messages])->all() ?: ['data.name' => $e->getMessage()]);
        }
    }

    private function profileUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : abort(403);
    }

    /**
     * Data kartu profil & info akun (baca saja).
     *
     * @return array{name: string, email: string, role: string, tenant: string, rows: list<array{label: string, value: string}>}
     */
    private static function accountInfo(User $user): array
    {
        // Dimuat ulang: user sesi bisa belum punya semua kolom (strict mode)
        $user = $user->fresh() ?? $user;
        $tz = 'Asia/Jakarta';
        $outlets = $user->accessibleOutlets()->orderBy('name')->pluck('name')->implode(', ');

        return [
            'name' => $user->name,
            'email' => $user->email ?? '',
            'role' => (string) $user->role->getLabel(),
            'tenant' => $user->tenant->name ?? '',
            'rows' => [
                ['label' => 'Outlet', 'value' => $outlets !== '' ? $outlets : '—'],
                ['label' => 'Username kasir web', 'value' => $user->username ?? '—'],
                ['label' => 'Status email', 'value' => $user->email_verified_at !== null ? 'Terverifikasi' : 'Belum diverifikasi'],
                ['label' => 'Terakhir masuk', 'value' => $user->last_login_at?->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') ?? '—'],
                ['label' => 'Bergabung', 'value' => $user->created_at?->setTimezone($tz)->locale('id')->translatedFormat('j M Y') ?? '—'],
            ],
        ];
    }

    /**
     * Sesi dashboard (tabel sessions) & perangkat aplikasi (token API) milik user.
     *
     * @return array{sessions: list<array{agent: string, ip: string|null, last: string, current: bool}>, tokens: list<array{name: string, last: string}>}
     */
    private static function sessions(User $user): array
    {
        $tz = 'Asia/Jakarta';
        $format = fn (?int $ts): string => $ts !== null ? now()->setTimestamp($ts)->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') : '—';

        $sessions = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->limit(20)->get()
            ->map(fn (object $s): array => [
                'agent' => self::agent((string) ($s->user_agent ?? '')),
                'ip' => $s->ip_address !== null ? (string) $s->ip_address : null,
                'last' => $format((int) $s->last_activity),
                'current' => $s->id === session()->getId(),
            ])->all();

        $tokens = PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
            ->with('device:id,name')->latest('last_used_at')->limit(20)->get()
            ->map(fn (PersonalAccessToken $t): array => [
                'name' => $t->device->name ?? $t->name,
                'last' => $format($t->last_used_at?->getTimestamp() ?? $t->created_at?->getTimestamp()),
            ])->all();

        return ['sessions' => array_values($sessions), 'tokens' => array_values($tokens)];
    }

    /** Ringkas user agent: "Chrome · macOS". */
    private static function agent(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'perangkat lain',
        };

        return $browser.' · '.$os;
    }
}
