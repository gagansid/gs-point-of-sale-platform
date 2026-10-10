<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Outlet\Data\OutletSettingsData;
use App\Actions\Outlet\UpdateOutletSettings;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Http\Requests\Api\V1\Outlet\OutletSettingsRequest;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LogicException;
use UnitEnum;

/**
 * Pengaturan → Profil outlet (SPEC: Pengaturan Outlet, permission outlet.settings).
 * Melihat cukup role berizin (tetap bisa saat hanya-baca); menyimpan butuh can() (ADR 0009).
 */
final class OutletSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Profil outlet';

    protected static ?string $title = 'Profil outlet';

    protected static ?string $slug = 'pengaturan/outlet';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission('outlet.settings');
    }

    public function mount(): void
    {
        $outlet = CurrentOutlet::getOrFail();
        $limits = $outlet->discount_limits ?? config('pos.outlet_defaults.discount_limits');

        $this->settingsForm()->fill([
            ...$outlet->only(['code', 'name', 'address', 'timezone', 'tax_rate', 'tax_inclusive', 'service_charge_rate', 'rounding', 'receipt_header', 'receipt_footer']),
            'discount_limits' => ['cashier' => $limits['cashier'] ?? 0, 'supervisor' => $limits['supervisor'] ?? 0],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $rules = OutletSettingsRequest::fieldRules();
        $canEdit = self::canEdit();

        return $schema
            ->statePath('data')
            ->disabled(! $canEdit)
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make('Profil')
                        ->description('Tampil di struk dan aplikasi kasir')
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')->label('Nama outlet')->required()->rules($rules['name']),
                            TextInput::make('code')->label('Kode outlet')->disabled()->dehydrated(false)
                                ->helperText('Awalan nomor order, tidak bisa diubah'),
                            Textarea::make('address')->label('Alamat')->rows(2)->rules($rules['address'])->columnSpanFull(),
                            Select::make('timezone')->label('Zona waktu')->options(Timezones::OPTIONS)->required()->native(false)
                                ->helperText('Dipakai untuk tanggal nomor order & laporan harian'),
                        ]),

                    Section::make('Pajak, service & pembulatan')
                        ->description('Berlaku untuk transaksi berikutnya; transaksi lama tidak berubah')
                        ->columns(2)
                        ->schema([
                            TextInput::make('tax_rate')->label('Pajak (PB1/PPN)')->suffix('%')->numeric()->required()
                                ->rules($rules['tax_rate'])->placeholder('11'),
                            TextInput::make('service_charge_rate')->label('Service charge')->suffix('%')->numeric()->required()
                                ->rules($rules['service_charge_rate'])->placeholder('5'),
                            Toggle::make('tax_inclusive')->label('Harga menu sudah termasuk pajak')
                                ->helperText('Aktif: pajak dihitung dari dalam harga. Nonaktif: pajak ditambahkan di atas total'),
                            Select::make('rounding')->label('Pembulatan total')->native(false)->required()
                                ->options(collect(UpdateOutletSettings::ROUNDING_OPTIONS)->mapWithKeys(fn (int $value): array => [
                                    $value => $value === 0 ? 'Tanpa pembulatan' : 'Ke Rp'.number_format($value, 0, ',', '.').' terdekat',
                                ])->all()),
                        ]),

                    Section::make('Struk')
                        ->description('Teks di bagian atas & bawah struk')
                        ->columns(2)
                        ->schema([
                            Textarea::make('receipt_header')->label('Header struk')->rows(3)->rules($rules['receipt_header'])
                                ->placeholder('Jl. Merdeka No. 1, Bandung'),
                            Textarea::make('receipt_footer')->label('Footer struk')->rows(3)->rules($rules['receipt_footer'])
                                ->placeholder('Terima kasih, sampai jumpa lagi'),
                        ]),
                ]),

                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Batas diskon manual')
                        ->description('Diskon di atas batas butuh PIN atasan. Manager & owner tidak dibatasi')
                        ->schema([
                            TextInput::make('discount_limits.cashier')->label('Kasir')->suffix('%')->numeric()->required()
                                ->rules($rules['discount_limits.cashier']),
                            TextInput::make('discount_limits.supervisor')->label('Supervisor')->suffix('%')->numeric()->required()
                                ->rules($rules['discount_limits.supervisor']),
                        ]),
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
                        Action::make('save')->label('Simpan')->submit('save')->keyBindings(['mod+s'])
                            ->visible(fn (): bool => self::canEdit()),
                    ])->alignEnd(),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canEdit(), 403);

        $data = $this->settingsForm()->getState();
        $outlet = app(UpdateOutletSettings::class)->handle(CurrentOutlet::getOrFail(), OutletSettingsData::fromArray($data));

        $this->settingsForm()->fill([...$data, 'code' => $outlet->code]);

        Notification::make()->success()->title('Setelan outlet disimpan')->send();
    }

    /** Menyimpan: owner & tenant tidak hanya-baca. */
    private static function canEdit(): bool
    {
        return auth()->user()?->can('outlet.settings') ?? false;
    }

    private function settingsForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form profil outlet tidak ditemukan');
    }
}
