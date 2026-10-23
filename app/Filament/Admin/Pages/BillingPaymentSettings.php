<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\UpdateBillingMidtransSettings;
use App\Actions\Admin\UpdateBillingPaymentSettings;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Actions\SettingsFormActions;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use App\Models\Bank;
use App\Support\Edition;
use App\Support\SystemSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use LogicException;
use UnitEnum;

/**
 * Langganan → Pembayaran langganan (Q123): rekening & QRIS statis untuk transfer manual tagihan langganan, petunjuk
 * untuk owner, dan Midtrans vendor (QRIS/VA otomatis): mode sandbox/produksi + server key per mode.
 */
final class BillingPaymentSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Langganan';

    protected static ?string $navigationLabel = 'Pembayaran langganan';

    protected static ?string $title = 'Pembayaran langganan';

    protected static ?string $slug = 'billing-payment';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Edition::isSaas() && parent::canAccess();
    }

    public function getSubheading(): string
    {
        return 'Cara owner membayar tagihan langganan';
    }

    public function mount(): void
    {
        $settings = app(SystemSettings::class);

        $this->settingsForm()->fill([
            'accounts' => $settings->billingBankAccounts(),
            'qris_image' => $settings->billingQrisImage(),
            'manual_note' => $settings->billingManualNote(),
            'midtrans_mode' => $settings->billingMidtransMode(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Section::make('Rekening transfer manual')
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->description('Tampil ke owner saat memilih Transfer manual. Maks. '.SystemSettings::BILLING_ACCOUNTS_MAX.' rekening. Daftar bank & logo di Langganan → Bank')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('accounts')->hiddenLabel()
                            ->table([
                                TableColumn::make('Bank')->width('280px')->markAsRequired(),
                                TableColumn::make('Nomor rekening')->width('190px')->markAsRequired(),
                                TableColumn::make('Atas nama')->markAsRequired(),
                            ])
                            ->compact()
                            ->schema([
                                // Pilihan berlogo (HTML dari data master; nama di-escape di Bank::optionHtml)
                                Select::make('bank_id')->hiddenLabel()->required()->searchable()->allowHtml()->native(false)
                                    ->options(fn (): array => Bank::htmlOptions())
                                    ->getSearchResultsUsing(fn (string $search): array => Bank::htmlOptions($search))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($bank = Bank::query()->find($value)) ? Bank::optionHtml($bank) : null),
                                TextInput::make('number')->hiddenLabel()->required()->maxLength(30)->placeholder('1234567890')
                                    ->extraInputAttributes(['inputmode' => 'numeric', 'class' => 'font-mono']),
                                TextInput::make('holder')->hiddenLabel()->required()->maxLength(80)->placeholder('PT Abati Teknologi'),
                            ])
                            ->maxItems(SystemSettings::BILLING_ACCOUNTS_MAX)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah rekening')
                            ->reorderableWithDragAndDrop(false),
                        Textarea::make('manual_note')->label('Petunjuk untuk owner')->rows(3)->maxLength(1000)
                            ->placeholder('mis. Transfer sesuai nominal tagihan. Verifikasi pada jam kerja (Senin–Jumat 09.00–17.00).'),
                    ]),
                Section::make('QRIS statis')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->description('Opsional. Owner scan lalu unggah bukti seperti transfer manual')
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->schema([
                        FileUpload::make('qris_image')->hiddenLabel()->image()->disk('public')
                            ->directory(UpdateBillingPaymentSettings::QRIS_DIRECTORY)->visibility('public')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(1024)
                            ->helperText('PNG/JPG/WEBP, maks. 1 MB'),
                    ]),
                Section::make('Pembayaran otomatis (Midtrans)')
                    ->icon(Heroicon::OutlinedBolt)
                    ->description('QRIS & virtual account, lunas otomatis lewat webhook. Key tersimpan terenkripsi, diuji ke Midtrans sebelum disimpan')
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('midtrans_mode')->label('Mode aktif')->inline()->required()
                            ->options(SystemSettings::MIDTRANS_MODES)
                            ->colors(['sandbox' => 'warning', 'production' => 'success'])
                            ->icons(['sandbox' => Heroicon::OutlinedBeaker, 'production' => Heroicon::OutlinedCheckBadge])
                            ->helperText('Sandbox untuk uji coba (uang tidak sungguhan). Produksi untuk pembayaran nyata')
                            ->columnSpanFull(),
                        TextInput::make('midtrans_sandbox_key')->label('Server key sandbox (dev)')->password()->revealable()
                            ->maxLength(120)->autocomplete('off')
                            ->placeholder(fn (): string => self::keyPlaceholder('sandbox', 'SB-Mid-server-…'))
                            ->helperText(fn (): string => self::keyStatus('sandbox')),
                        TextInput::make('midtrans_production_key')->label('Server key produksi')->password()->revealable()
                            ->maxLength(120)->autocomplete('off')
                            ->placeholder(fn (): string => self::keyPlaceholder('production', 'Mid-server-…'))
                            ->helperText(fn (): string => self::keyStatus('production')),
                        Toggle::make('midtrans_clear_keys')->label('Hapus key tersimpan (kembali ke .env)')->columnSpanFull(),
                        Placeholder::make('webhook')->label('URL notifikasi (isi di dashboard Midtrans, kedua mode)')->columnSpanFull()
                            ->content(fn (): HtmlString => new HtmlString('<code class="text-xs">'.e(rtrim((string) config('app.url'), '/').'/webhooks/billing/midtrans').'</code>')),
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
        $data = $this->settingsForm()->getState();

        try {
            // Midtrans dulu: key salah ditolak sebelum setelan lain tersimpan
            $online = app(UpdateBillingMidtransSettings::class)->handle($this->admin(), $data);
            app(UpdateBillingPaymentSettings::class)->handle($this->admin(), $data);
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        // Key tidak pernah diisi ulang ke form
        $this->settingsForm()->fill([...$data, 'midtrans_sandbox_key' => null, 'midtrans_production_key' => null, 'midtrans_clear_keys' => false]);

        $notification = Notification::make()->success()->title('Setelan pembayaran disimpan');
        if (! $online) {
            $notification->warning()->body('Key Midtrans mode '.SystemSettings::MIDTRANS_MODES[app(SystemSettings::class)->billingMidtransMode()].' belum diisi: owner hanya bisa transfer manual');
        }
        $notification->send();
    }

    private static function keyStatus(string $mode): string
    {
        return match (app(SystemSettings::class)->billingMidtransKeySource($mode)) {
            'saved' => 'Tersimpan di panel. Kosongkan bila tidak diubah',
            'env' => 'Dari .env. Isi untuk menyimpan di panel',
            default => 'Belum diisi',
        };
    }

    private static function keyPlaceholder(string $mode, string $example): string
    {
        return app(SystemSettings::class)->billingMidtransKeySource($mode) !== null ? '•••••••• (tersimpan)' : $example;
    }

    private function settingsForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form setelan pembayaran tidak ditemukan');
    }

    private function admin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
