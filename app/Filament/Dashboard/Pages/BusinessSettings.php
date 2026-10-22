<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Tenant\UpdateBusinessSettings;
use App\Enums\BusinessType;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Actions\SettingsFormActions;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantAppearance;
use App\Support\TenantPreferences;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use LogicException;
use UnitEnum;

/**
 * Pengaturan → Bisnis & tampilan (Q128, owner): profil bisnis (nama, jenis usaha, logo sidebar), warna utama
 * dashboard (preset / bebas), gaya sidebar, mode tampilan bawaan, panduan awal. Berlaku untuk semua pengguna bisnis
 * ini di dashboard. Kode bisnis (login kasir web) tidak bisa diubah di sini.
 */
final class BusinessSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Bisnis & tampilan';

    protected static ?string $title = 'Bisnis & tampilan';

    protected static ?string $slug = 'settings/business';

    protected static ?int $navigationSort = 0;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasFullDashboard() && $user->hasPermission('business.settings');
    }

    public function getSubheading(): string
    {
        return 'Profil bisnis dan tampilan dashboard untuk semua pengguna';
    }

    public function mount(): void
    {
        $tenant = $this->tenant();

        $this->settingsForm()->fill([
            'name' => $tenant->name,
            'business_type' => $tenant->business_type->value,
            'logo' => TenantAppearance::logo($tenant),
            'color' => TenantAppearance::colorKey($tenant),
            'custom_color' => TenantAppearance::colorKey($tenant) === TenantAppearance::CUSTOM ? TenantAppearance::hex($tenant) : '#0f766e',
            'sidebar' => TenantAppearance::sidebar($tenant),
            'theme_mode' => TenantAppearance::themeMode($tenant)->value,
            'show_setup_guide' => ! TenantPreferences::setupDismissed($tenant),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $tenant = $this->tenant();

        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Section::make('Profil bisnis')->icon(Heroicon::OutlinedBuildingOffice2)
                    ->description('Nama tampil di dashboard, email, dan dokumen tagihan')
                    ->columns(2)->columnSpan(['default' => 3, 'lg' => 2])
                    ->schema([
                        TextInput::make('name')->label('Nama bisnis')->required()->maxLength(100),
                        Select::make('business_type')->label('Jenis usaha')->required()->native(false)
                            ->options(collect(BusinessType::cases())->mapWithKeys(fn (BusinessType $t): array => [$t->value => $t->getLabel()])->all()),
                        Placeholder::make('slug')->label('Kode bisnis')
                            ->content(new HtmlString('<code class="text-sm">'.e($tenant->slug).'</code>'))
                            ->helperText('Dipakai kasir saat masuk dengan username. Hubungi tim gs.POS untuk mengubah'),
                        FileUpload::make('logo')->label('Logo bisnis')->image()->disk('public')
                            ->directory(UpdateBusinessSettings::logoDirectory($tenant))->visibility('public')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(1024)
                            ->imageEditor(false)
                            ->helperText('Persegi, PNG/JPG/WEBP maks. 1 MB. Tampil di sidebar menggantikan logo gs.POS'),
                    ]),
                Section::make('Pratinjau')->icon(Heroicon::OutlinedEye)
                    ->description('Perkiraan tampilan dengan pilihan saat ini')
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->schema([
                        Placeholder::make('preview')->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => new HtmlString(view('filament.dashboard.business.preview', [
                                'hex' => self::previewHex($get),
                                'sidebar' => (string) ($get('sidebar') ?: 'colored'),
                                'name' => (string) ($get('name') ?: $tenant->name),
                            ])->render())),
                    ]),
                Section::make('Tampilan dashboard')->icon(Heroicon::OutlinedSwatch)
                    ->description('Berlaku untuk semua pengguna bisnis ini. Warna tombol, tautan, ikon, dan sidebar')
                    ->columns(2)->columnSpanFull()
                    ->schema([
                        ViewField::make('color')->label('Warna utama')->view('filament.dashboard.business.color-swatches')
                            ->viewData(['presets' => TenantAppearance::PRESETS])->columnSpanFull()->live(),
                        ColorPicker::make('custom_color')->label('Warna bebas')->live(onBlur: true)
                            ->visible(fn (Get $get): bool => $get('color') === TenantAppearance::CUSTOM)
                            ->helperText('Pilih warna yang cukup gelap agar teks putih di tombol terbaca'),
                        ToggleButtons::make('sidebar')->label('Gaya sidebar')->inline()->required()->live()
                            ->options(TenantAppearance::SIDEBARS)
                            ->icons(['colored' => Heroicon::OutlinedPaintBrush, 'light' => Heroicon::OutlinedSun]),
                        ToggleButtons::make('theme_mode')->label('Mode tampilan bawaan')->inline()->required()
                            ->options(TenantAppearance::THEME_MODES)
                            ->icons(['light' => Heroicon::OutlinedSun, 'dark' => Heroicon::OutlinedMoon, 'system' => Heroicon::OutlinedComputerDesktop])
                            ->helperText('Pengguna tetap bisa memilih sendiri lewat ikon tema di topbar'),
                    ]),
                Section::make('Panduan')->icon(Heroicon::OutlinedLightBulb)
                    ->description('Kartu "Mulai berjualan" di Beranda untuk bisnis yang baru mulai')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('show_setup_guide')->label('Tampilkan panduan Mulai berjualan')
                            ->helperText('Tetap tersembunyi otomatis bila semua langkah selesai atau sudah ada transaksi'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([SettingsFormActions::make(self::getUrl())]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->settingsForm()->getState();

        try {
            app(UpdateBusinessSettings::class)->handle($this->tenant(), $data);
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Setelan bisnis disimpan')->send();
        // Muat ulang agar warna & logo baru langsung berlaku di seluruh halaman
        $this->redirect(self::getUrl());
    }

    private static function previewHex(Get $get): string
    {
        $key = (string) $get('color');
        if ($key === TenantAppearance::CUSTOM) {
            $hex = strtolower((string) $get('custom_color'));

            return TenantAppearance::isValidHex($hex) ? $hex : TenantAppearance::PRESETS[TenantAppearance::DEFAULT_COLOR][1];
        }

        return TenantAppearance::PRESETS[$key][1] ?? TenantAppearance::PRESETS[TenantAppearance::DEFAULT_COLOR][1];
    }

    private function tenant(): Tenant
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return Tenant::query()->findOrFail($user->tenant_id);
    }

    private function settingsForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form setelan bisnis tidak ditemukan');
    }
}
