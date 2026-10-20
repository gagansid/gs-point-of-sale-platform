<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\UpdateBillingPaymentSettings;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Actions\SettingsFormActions;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use App\Models\Bank;
use App\Services\Payment\MidtransClient;
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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use LogicException;
use UnitEnum;

/**
 * Sistem → Pembayaran langganan (Q123): rekening & QRIS statis untuk transfer manual tagihan langganan, petunjuk
 * untuk owner, dan status Midtrans vendor (QRIS/VA otomatis, key di .env).
 */
final class BillingPaymentSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Pembayaran langganan';

    protected static ?string $title = 'Pembayaran langganan';

    protected static ?string $slug = 'billing-payment';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Edition::isSaas() && parent::canAccess();
    }

    public function getSubheading(): string
    {
        return 'Cara owner membayar tagihan langganan: QRIS & VA otomatis (Midtrans) atau transfer manual dengan bukti';
    }

    public function mount(): void
    {
        $settings = app(SystemSettings::class);

        $this->settingsForm()->fill([
            'accounts' => $settings->billingBankAccounts(),
            'qris_image' => $settings->billingQrisImage(),
            'manual_note' => $settings->billingManualNote(),
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
                    ->description('Tampil ke owner saat memilih Transfer manual. Maks. '.SystemSettings::BILLING_ACCOUNTS_MAX.' rekening. Daftar bank & logo di Sistem → Bank')
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
                    ->description('QRIS & virtual account, lunas otomatis lewat webhook')
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->schema([
                        Placeholder::make('midtrans')->hiddenLabel()->content(fn (): HtmlString => new HtmlString(Blade::render(
                            '<div class="flex flex-wrap items-center gap-2 text-sm"><x-filament::badge :color="$on ? \'success\' : \'gray\'">{{ $on ? \'Aktif\' : \'Belum diatur\' }}</x-filament::badge><span>{!! $text !!}</span></div>',
                            ['on' => MidtransClient::vendor() !== null, 'text' => MidtransClient::vendor() !== null
                                ? 'Mode '.(config('pos.billing.midtrans_production') ? 'produksi' : 'sandbox').'. Owner bisa memilih QRIS atau Transfer bank (VA).'
                                : 'Isi <code>POS_BILLING_MIDTRANS_SERVER_KEY</code> di .env untuk mengaktifkan QRIS & VA otomatis. Tanpa key, owner hanya bisa transfer manual.'],
                        ))),
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
            app(UpdateBillingPaymentSettings::class)->handle($this->admin(), $data);
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Setelan pembayaran disimpan')->send();
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
