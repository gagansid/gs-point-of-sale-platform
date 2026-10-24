<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Payment\TestPaymentGateway;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Actions\SettingsFormActions;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\PaymentGatewaySetting;
use App\Models\User;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/**
 * Pengaturan → Pembayaran online (ADR 0014, Q72; izin payment_gateway.manage): akun Midtrans milik owner untuk
 * pesanan dari meja. Key disimpan terenkripsi dan tidak pernah ditampilkan lagi; kosong = key lama tetap.
 */
final class PaymentGatewaySettings extends DashboardPage
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pembayaran online';

    protected static ?string $title = 'Pembayaran online';

    protected static ?string $slug = 'settings/online-payment';

    protected static ?int $navigationSort = 6;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function canUsePage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('self_order') && Features::enabled('online_payment') && $user->hasPermission('payment_gateway.manage');
    }

    public function mount(): void
    {
        $setting = PaymentGatewaySetting::current();

        $this->settingsForm()->fill([
            'enabled' => $setting->enabled,
            'is_production' => $setting->is_production,
            'enabled_payments' => $setting->enabled_payments ?? array_keys(PaymentGatewaySetting::PAYMENT_TYPES),
            'server_key' => null,
            'client_key' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $setting = PaymentGatewaySetting::current();
        $stored = fn (string $key): string => filled($setting->getAttribute($key)) ? 'Tersimpan — kosongkan bila tidak diganti' : '';

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Midtrans')->icon(Heroicon::OutlinedGlobeAlt)
                    ->description('Pelanggan membayar pesanan dari meja langsung ke akun Midtrans Anda; biaya transaksi (MDR) mengikuti kontrak Anda dengan Midtrans')
                    ->schema([
                        Toggle::make('enabled')->label('Aktifkan pembayaran online')->live(),
                        Toggle::make('is_production')->label('Mode production')
                            ->helperText('Matikan untuk uji coba (sandbox) dengan key sandbox Midtrans'),
                        TextInput::make('server_key')->label('Server Key')->password()->revealable()->autocomplete('new-password')
                            ->placeholder($stored('server_key'))->maxLength(200),
                        TextInput::make('client_key')->label('Client Key')->password()->revealable()->autocomplete('new-password')
                            ->placeholder($stored('client_key'))->maxLength(200),
                        CheckboxList::make('enabled_payments')->label('Metode yang ditawarkan')->columns(2)
                            ->options(PaymentGatewaySetting::PAYMENT_TYPES)
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        Placeholder::make('webhook')->label('URL notifikasi (Payment Notification URL)')
                            ->content(fn (): HtmlString => new HtmlString('<code class="text-sm break-all">'.e(self::webhookUrl()).'</code><br><span class="text-sm text-gray-500">Salin ke dashboard Midtrans: Settings → Configuration → Payment Notification URL</span>')),
                        Placeholder::make('verified')->label('Status koneksi')
                            ->content(fn (): string => $setting->verified_at !== null ? 'Terhubung (dites '.$setting->verified_at->diffForHumans().')' : 'Belum dites'),
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
                    SettingsFormActions::make(self::getUrl(), extra: [
                        Action::make('test')->label('Tes koneksi')->color('gray')->icon(Heroicon::OutlinedSignal)
                            ->action(fn () => $this->testConnection()),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->settingsForm()->getState();

        try {
            app(UpdatePaymentGateway::class)->handle([
                'enabled' => (bool) ($data['enabled'] ?? false),
                'is_production' => (bool) ($data['is_production'] ?? false),
                'server_key' => $data['server_key'] ?? null,
                'client_key' => $data['client_key'] ?? null,
                'enabled_payments' => array_values((array) ($data['enabled_payments'] ?? [])),
            ]);
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($e->validator->errors()->first())->send();

            return;
        }

        // Key tidak pernah dikembalikan ke form
        $this->settingsForm()->fill([...$data, 'server_key' => null, 'client_key' => null]);
        Notification::make()->success()->title('Pembayaran online disimpan')->send();
    }

    public function testConnection(): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            app(TestPaymentGateway::class)->handle();
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Terhubung ke Midtrans')->send();
    }

    private static function webhookUrl(): string
    {
        $user = auth()->user();

        return route('webhooks.midtrans', ['tenant' => $user instanceof User ? $user->tenant_id : '']);
    }

    private function settingsForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form pembayaran online tidak ditemukan');
    }
}
