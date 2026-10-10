<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\UpdateOnboardingSettings;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use App\Support\Edition;
use App\Support\SystemSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LogicException;
use UnitEnum;

/**
 * Sistem → Pendaftaran (ADR 0009): lama trial tenant baru & buka/tutup daftar sendiri di gspos.id/register.
 */
final class OnboardingSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Pendaftaran';

    protected static ?string $title = 'Pendaftaran';

    protected static ?string $slug = 'onboarding';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Jual putus: tanpa trial & daftar mandiri (ADR 0009). */
    public static function canAccess(): bool
    {
        return Edition::isSaas() && parent::canAccess();
    }

    public function mount(): void
    {
        $settings = app(SystemSettings::class);

        $this->settingsForm()->fill([
            'trial_days' => $settings->trialDays(),
            'signup_enabled' => $settings->signupEnabled(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Trial & daftar sendiri')
                    ->description('Berlaku untuk bisnis baru. Bisnis yang sudah ada tidak berubah.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('trial_days')
                            ->label('Lama trial')
                            ->suffix('hari')
                            ->integer()
                            ->minValue(SystemSettings::TRIAL_DAYS_MIN)
                            ->maxValue(SystemSettings::TRIAL_DAYS_MAX)
                            ->required()
                            ->helperText('Setelah trial habis tanpa berlangganan, bisnis menjadi hanya-baca'),
                        Toggle::make('signup_enabled')
                            ->label('Buka pendaftaran sendiri')
                            ->helperText('Calon pelanggan bisa mendaftar di halaman depan tanpa menunggu tim sales'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Simpan')->submit('save')->keyBindings(['mod+s']),
                    ])->alignEnd(),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->settingsForm()->getState();

        app(UpdateOnboardingSettings::class)->handle($this->admin(), (int) $data['trial_days'], (bool) $data['signup_enabled']);

        Notification::make()->success()->title('Setelan pendaftaran disimpan')->send();
    }

    private function settingsForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form setelan pendaftaran tidak ditemukan');
    }

    private function admin(): Admin
    {
        $admin = auth('admin')->user();

        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
