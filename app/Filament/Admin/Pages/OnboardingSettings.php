<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\UpdateOnboardingSettings;
use App\Actions\Tenant\RegisterTenant;
use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Actions\SettingsFormActions;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Edition;
use App\Support\SystemSettings;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/**
 * Sistem → Pendaftaran (ADR 0009): ringkasan pendaftar mandiri, pendaftar terbaru, dan setelan: buka/tutup daftar
 * sendiri + pesan saat ditutup, batas per hari, lama trial & paket selama trial, domain email diblokir, email tim.
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

    public function getSubheading(): string
    {
        return 'Daftar mandiri, trial, dan perlindungan spam';
    }

    public function mount(): void
    {
        $settings = app(SystemSettings::class);

        $this->settingsForm()->fill([
            'trial_days' => $settings->trialDays(),
            'signup_enabled' => $settings->signupEnabled(),
            // Paket trial yang kini nonaktif/khusus dikosongkan agar form tetap bisa disimpan
            'trial_plan_id' => Plan::query()->where('is_active', true)->where('is_public', true)->whereKey($settings->trialPlanId())->value('id'),
            'signup_daily_limit' => $settings->signupDailyLimit(),
            'signup_blocked_domains' => $settings->signupBlockedDomains(),
            'signup_notify_emails' => $settings->signupNotifyEmails(),
            'signup_closed_message' => $settings->signupClosedMessage(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Section::make('Daftar sendiri')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->description('Calon pelanggan mendaftar di halaman depan tanpa menunggu tim sales')
                    ->columns(2)
                    ->schema([
                        Toggle::make('signup_enabled')->label('Buka pendaftaran sendiri')->columnSpanFull()
                            ->helperText(fn (): string => 'Halaman daftar: '.self::signupUrl()),
                        TextInput::make('signup_daily_limit')->label('Batas pendaftar per hari')->integer()->minValue(0)
                            ->maxValue(SystemSettings::SIGNUP_DAILY_LIMIT_MAX)->suffix('bisnis')->default(0)
                            ->helperText('0 = tanpa batas; dihitung per hari WIB'),
                        TextInput::make('signup_closed_message')->label('Pesan saat ditutup')->maxLength(500)
                            ->placeholder('Tim kami siap membantu menyiapkan akun Anda.')
                            ->helperText('Tampil di halaman daftar saat pendaftaran ditutup'),
                    ]),
                Section::make('Trial')
                    ->icon(Heroicon::OutlinedClock)
                    ->description('Berlaku untuk bisnis baru. Bisnis yang sudah ada tidak berubah')
                    ->columns(2)
                    ->schema([
                        TextInput::make('trial_days')->label('Lama trial')->suffix('hari')->integer()
                            ->minValue(SystemSettings::TRIAL_DAYS_MIN)->maxValue(SystemSettings::TRIAL_DAYS_MAX)->required()
                            ->helperText('Setelah trial habis tanpa berlangganan, bisnis menjadi hanya-baca'),
                        Select::make('trial_plan_id')->label('Paket selama trial')->placeholder('Semua fitur, tanpa batas')
                            ->options(fn (): array => Plan::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                            ->helperText('Paket publik. Fitur & kuota paket berlaku selama trial; kosong = semua fitur'),
                    ]),
                Section::make('Perlindungan')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->description('Tolak email sekali pakai/disposable. Subdomain ikut diblokir')
                    ->schema([
                        TagsInput::make('signup_blocked_domains')->label('Domain email diblokir')
                            ->placeholder('mis. mailinator.com, tekan Enter')->splitKeys([',', ' '])
                            ->helperText('Huruf kecil tanpa @. Maks. 200 domain'),
                    ]),
                Section::make('Notifikasi tim')
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->description('Email ke tim sales/onboarding setiap ada pendaftar baru')
                    ->schema([
                        TagsInput::make('signup_notify_emails')->label('Email penerima')
                            ->placeholder('mis. sales@gspos.id, tekan Enter')->splitKeys([',', ' '])
                            ->nestedRecursiveRules(['email', 'max:150'])
                            ->helperText('Maks. 10 email. Kosong = tanpa notifikasi'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.pages.onboarding-overview')->viewData(fn (): array => $this->overview()),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    SettingsFormActions::make(self::getUrl()),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->settingsForm()->getState();

        try {
            app(UpdateOnboardingSettings::class)->handle($this->admin(), $data);
        } catch (ValidationException $e) {
            // Kunci aturan server (mis. signup_blocked_domains.0) → field form
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.explode('.', $key)[0] => $messages])->all());
        }

        Notification::make()->success()->title('Setelan pendaftaran disimpan')->send();
    }

    /**
     * Ringkasan pendaftar mandiri (tanpa pembuat = bukan dibuat admin/sales) untuk view overview.
     *
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $tz = (string) config('pos.default_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay()->utc();
        $self = fn () => Tenant::query()->whereNull('created_by');
        $last90 = $self()->where('created_at', '>=', $today->subDays(89));
        $total90 = (clone $last90)->count();
        $converted90 = (clone $last90)->where('status', TenantStatus::Active)->whereNotNull('billing_cycle')->count();
        $settings = app(SystemSettings::class);

        /** @var Collection<int, Tenant> $recent */
        $recent = $self()->with(['plan:id,name'])->withCount('outlets')->latest()->limit(8)->get();

        return [
            'open' => $settings->signupEnabled(),
            'limit' => $settings->signupDailyLimit(),
            'today' => RegisterTenant::signupsToday(),
            'week' => $self()->where('created_at', '>=', $today->subDays(6))->count(),
            'month' => $self()->where('created_at', '>=', $today->subDays(29))->count(),
            'trials' => Tenant::query()->where('status', TenantStatus::Trial)->where('subscription_ends_at', '>', now())->count(),
            'conversion' => $total90 > 0 ? (int) round($converted90 / $total90 * 100) : null,
            'converted' => $converted90,
            'total90' => $total90,
            'recent' => $recent,
            'tenantUrl' => fn (Tenant $t): string => TenantResource::getUrl('view', ['record' => $t]),
            'allUrl' => TenantResource::getUrl('index'),
            'timezone' => $tz,
        ];
    }

    private static function signupUrl(): string
    {
        return route('signup');
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
