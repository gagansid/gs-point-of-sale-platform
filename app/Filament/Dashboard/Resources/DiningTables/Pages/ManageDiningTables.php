<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\DiningTables\Pages;

use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Filament\Dashboard\Resources\DiningTables\DiningTableResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\SelfOrderSetting;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

final class ManageDiningTables extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = DiningTableResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::make(DiningTable::class);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->settingsAction()];
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            Action::make('printAll')->label('Cetak QR')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (): string => route('filament.dashboard.tables.print'))
                ->openUrlInNewTab(),
            CreateAction::make()
                ->label('Tambah')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => DiningTableResource::canCreate())
                ->modalHeading('Tambah meja')
                ->modalWidth('lg')
                ->successNotificationTitle('Meja ditambahkan')
                ->using(fn (array $data, CreateAction $action): DiningTable => RunBusinessAction::run(
                    fn (): DiningTable => app(SaveDiningTable::class)->handle(null, self::targetOutlet($data['outlet_id'] ?? null), $data),
                    $action,
                )),
        ];
    }

    /** Setelan self-order outlet (ADR 0014, 0027): aktif, batas waktu bayar, jam layanan, metode bayar di HP, selesai otomatis, sambutan. */
    public function settingsAction(): Action
    {
        $choose = fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1;
        $values = fn (string $outletId): array => [
            'outlet_id' => $outletId,
            ...SelfOrderSetting::forOutlet($outletId)->only(['enabled', 'payment_timeout_minutes', 'allow_pay_at_cashier', 'allow_qris', 'allow_points', 'auto_complete_minutes', 'welcome_text']),
            'opens_at' => ($s = SelfOrderSetting::forOutlet($outletId))->opens_at !== null ? substr($s->opens_at, 0, 5) : null,
            'closes_at' => $s->closes_at !== null ? substr($s->closes_at, 0, 5) : null,
        ];

        return Action::make('selfOrderSettings')->label('Setelan self-order')->icon(Heroicon::OutlinedCog6Tooth)->color('gray')
            ->visible(fn (): bool => DiningTableResource::canCreate())
            ->modalHeading('Setelan self-order')
            ->modalDescription('Pelanggan memindai QR meja, memesan & membayar dari HP; pesanan lunas langsung masuk antrean')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Simpan')
            ->fillForm(fn (): array => $values(CurrentOutlet::getOrFail()->id))
            ->schema([
                Select::make('outlet_id')->label('Outlet')->required()->native(false)->live()
                    ->options(fn (): array => Outlet::query()->whereIn('id', CurrentOutlet::ids())->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(function (?string $state, Set $set) use ($values): void {
                        foreach ($state !== null ? $values($state) : [] as $key => $value) {
                            $set($key, $value);
                        }
                    })
                    ->visible($choose),
                Toggle::make('enabled')->label('Aktifkan pesan dari meja')->live(),
                TextInput::make('payment_timeout_minutes')->label('Batas waktu bayar')->integer()->minValue(5)->maxValue(120)->suffix('menit')
                    ->default(15)->helperText('Sejak pelanggan menekan Bayar; lewat batas = pesanan kedaluwarsa')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                TimePicker::make('opens_at')->label('Jam buka')->seconds(false)->requiredWith('closes_at')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                TimePicker::make('closes_at')->label('Jam tutup')->seconds(false)->requiredWith('opens_at')
                    ->helperText('Kosong = sepanjang hari')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                Toggle::make('allow_qris')->label('QRIS di HP')->default(true)
                    ->helperText('Butuh Pembayaran online (Midtrans) aktif dengan QRIS diizinkan')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                Toggle::make('allow_pay_at_cashier')->label('Pelanggan boleh bayar di kasir')->default(true)
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                Toggle::make('allow_points')->label('Bayar dengan poin member')->default(true)
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                TextInput::make('auto_complete_minutes')->label('Selesai otomatis setelah')->integer()->minValue(30)->maxValue(240)->suffix('menit')
                    ->default(60)->helperText('Pesanan lunas yang tidak ditandai Diantar ditutup otomatis')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                Textarea::make('welcome_text')->label('Teks sambutan')->rows(2)->maxLength(255)
                    ->visible(fn (Get $get): bool => (bool) $get('enabled')),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(DiningTableResource::canCreate(), 403);
                $outlet = self::targetOutlet($data['outlet_id'] ?? null);
                // Field tersembunyi (self-order nonaktif) tidak ikut state: nilai lama dipertahankan
                $current = SelfOrderSetting::forOutlet($outlet->id);
                RunBusinessAction::run(fn () => app(UpdateSelfOrderSettings::class)->handle($outlet, [
                    'payment_timeout_minutes' => $current->payment_timeout_minutes,
                    'allow_pay_at_cashier' => $current->allow_pay_at_cashier,
                    'allow_qris' => $current->allow_qris,
                    'allow_points' => $current->allow_points,
                    'auto_complete_minutes' => $current->auto_complete_minutes,
                    'opens_at' => $current->opens_at,
                    'closes_at' => $current->closes_at,
                    'welcome_text' => $current->welcome_text,
                    ...collect($data)->except('outlet_id')->all(),
                ]), $action);

                Notification::make()->success()->title('Setelan self-order disimpan')->send();
            });
    }

    /** Outlet tujuan: pilihan form (hanya outlet yang boleh diakses) atau outlet topbar. */
    private static function targetOutlet(?string $outletId): Outlet
    {
        return $outletId !== null && in_array($outletId, CurrentOutlet::ids(), true)
            ? Outlet::query()->findOrFail($outletId)
            : CurrentOutlet::getOrFail();
    }
}
